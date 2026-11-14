<?php

namespace App\Http\Controllers;

use App\Services\Ess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Employee Self-Service API (/api/ess/*). */
class EssController extends Controller
{
    public function __construct(private Ess $ess) {}

    private function emp(Request $r): object
    {
        $e = $this->ess->employeeFor($r->user());
        abort_unless($e, 403, 'Your login is not linked to an employee record. Ask HR to link your staff code.');
        return $e;
    }

    private function roles(Request $r): array
    {
        $u = $r->user();
        return ['approver' => $u->hasAnyRole(['ess_supervisor', 'ess_hr', 'super_admin']), 'hr' => $u->hasAnyRole(['ess_hr', 'super_admin'])];
    }

    /* ── profile + home dashboard ─────────────────────────────────────── */

    public function home(Request $r)
    {
        $u = $r->user(); $e = $this->ess->employeeFor($u); $roles = $this->roles($r);
        $today = now()->toDateString(); $year = now()->year; $mStart = now()->startOfMonth()->toDateString();
        $out = ['user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'username' => $u->username],
            'employee' => null, 'roles' => $roles, 'server_time' => now()->toIso8601String()];

        $out['notifications'] = DB::table('ess_notifications')->where('user_id', $u->id)->orderByDesc('id')->limit(6)->get();
        $out['unread'] = DB::table('ess_notifications')->where('user_id', $u->id)->whereNull('read_at')->count();
        $out['memos'] = $this->memoQuery($u->id)->limit(3)->get();
        $out['memos_unread'] = $this->memoQuery($u->id)->whereNull('mr.read_at')->count();
        $out['holidays'] = DB::table('public_holidays')->where('date', '>=', $today)->orderBy('date')->limit(4)->get();
        $out['approvals_pending'] = $roles['approver'] ? count($this->approvalQueue($r)) : 0;

        if ($e) {
            $out['employee'] = ['id' => $e->id, 'code' => $e->employee_id, 'name' => $e->name, 'role' => $e->job_level, 'office' => $this->ess->office($e->hq_region),
                'shift' => $e->shift, 'workdays' => $e->workdays ?: 6];
            $out['today'] = DB::table('attendances')->where(['employee_id' => $e->id, 'date' => $today])->first();
            $month = DB::table('attendances')->where('employee_id', $e->id)->whereBetween('date', [$mStart, $today])->get();
            $late = $month->filter(fn ($a) => $a->timeclock_in && $a->timeclock_in > '08:30:00' && $a->timeclock_in < '12:00:00')->count();
            $worked = $month->filter(fn ($a) => $a->timeclock_in && $a->timeclock_out && $a->timeclock_out > $a->timeclock_in);
            $hours = $worked->sum(fn ($a) => (strtotime($a->timeclock_out) - strtotime($a->timeclock_in)) / 3600);
            $ins = $month->filter(fn ($a) => $a->timeclock_in && $a->timeclock_in < '12:00:00')->map(fn ($a) => strtotime("1970-01-01 {$a->timeclock_in} UTC") / 3600);
            $avgIn = $ins->count() ? $ins->avg() : null;
            $out['month'] = ['present' => $month->whereIn('status', ['present', 'late'])->count(), 'leave' => $month->where('status', 'on_leave')->count(),
                'late' => $late, 'hours' => round($hours, 1), 'avg_in' => $avgIn ? sprintf('%02d:%02d', floor($avgIn), round(fmod($avgIn, 1) * 60)) : null,
                'on_time_pct' => $ins->count() ? round(($ins->count() - $late) / $ins->count() * 100) : null,
                'missing_out' => $month->filter(fn ($a) => $a->timeclock_in && !$a->timeclock_out && $a->date < $today)->count()];
            $out['week'] = DB::table('attendances')->where('employee_id', $e->id)->whereBetween('date', [now()->subDays(6)->toDateString(), $today])
                ->orderBy('date')->get(['date', 'status', 'timeclock_in', 'timeclock_out']);
            $out['balances'] = $this->ess->balances($e->id, $year);
            $out['my_pending'] = DB::table('ess_requests')->where(['employee_id' => $e->id, 'status' => 'pending'])->orderByDesc('id')->limit(5)->get();
            $ot = DB::table('ess_requests')->where(['employee_id' => $e->id, 'type' => 'overtime'])->whereIn('status', ['approved', 'pending'])->where('start_date', '>=', $mStart)->sum('quantity');
            $to = DB::table('ess_requests')->where(['employee_id' => $e->id, 'type' => 'timeoff'])->whereIn('status', ['approved', 'pending'])->where('start_date', '>=', $mStart)->count();
            $out['ot'] = ['hours' => (float) $ot, 'cap' => Ess::OT_CAP];
            $out['timeoff'] = ['used' => $to, 'limit' => Ess::TIMEOFF_PER_MONTH];
            $out['team_out'] = $this->teamOut($e, $today, now()->addDays(6)->toDateString());
            $out['nudges'] = $this->nudges($out);
        }
        return $out;
    }

    private function nudges(array $o): array
    {
        $n = [];
        if (empty($o['today']?->timeclock_in) && now()->hour >= 7 && now()->hour < 18 && !now()->isSunday())
            $n[] = ['tone' => 'info', 'icon' => 'fa-solid fa-fingerprint', 'text' => 'Anda belum clock-in hari ini.', 'action' => 'checkin'];
        if (($o['month']['missing_out'] ?? 0) > 0)
            $n[] = ['tone' => 'warn', 'icon' => 'fa-solid fa-door-open', 'text' => "{$o['month']['missing_out']} hari bulan ini tiada clock-out — maklumkan penyelia untuk pembetulan.", 'action' => 'attendance'];
        if (($o['month']['late'] ?? 0) >= 3)
            $n[] = ['tone' => 'warn', 'icon' => 'fa-solid fa-clock', 'text' => "Anda lewat {$o['month']['late']} kali bulan ini (selepas 08:30).", 'action' => 'attendance'];
        $annual = collect($o['balances'] ?? [])->firstWhere('code', 'annual');
        if ($annual && $annual['available'] >= 3 && now()->month >= 10)
            $n[] = ['tone' => 'good', 'icon' => 'fa-solid fa-umbrella-beach', 'text' => "Baki cuti tahunan {$annual['available']} hari — rancang sebelum hujung tahun.", 'action' => 'leave'];
        if (($o['ot']['hours'] ?? 0) >= Ess::OT_CAP * 0.8)
            $n[] = ['tone' => 'warn', 'icon' => 'fa-solid fa-business-time', 'text' => "OT bulan ini {$o['ot']['hours']} jam — had 104 jam sebulan.", 'action' => 'overtime'];
        if (($o['approvals_pending'] ?? 0) > 0)
            $n[] = ['tone' => 'info', 'icon' => 'fa-solid fa-stamp', 'text' => "{$o['approvals_pending']} permohonan menunggu kelulusan anda.", 'action' => 'approvals'];
        if (($o['memos_unread'] ?? 0) > 0)
            $n[] = ['tone' => 'info', 'icon' => 'fa-solid fa-bullhorn', 'text' => "{$o['memos_unread']} memo syarikat belum dibaca.", 'action' => 'memos'];
        return $n;
    }

    private function teamOut(object $e, string $from, string $to)
    {
        return DB::table('ess_requests as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->leftJoin('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.type', 'leave')->whereIn('r.status', ['approved', 'pending'])
            ->where(fn ($w) => $this->ess->sameOffice($w, 'e.hq_region', $e->hq_region))
            ->where('r.start_date', '<=', $to)->whereRaw('COALESCE(r.end_date, r.start_date) >= ?', [$from])
            ->orderBy('r.start_date')->limit(12)->get(['e.name', 'e.employee_id as code', 'r.start_date', 'r.end_date', 'r.status', 't.name_ms as leave', 't.color']);
    }

    /* ── clock in / out ───────────────────────────────────────────────── */

    public function clock(Request $r, string $dir)
    {
        $e = $this->emp($r);
        $d = $r->validate(['lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'accuracy' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:200', 'photo' => 'nullable|string|max:3500000']);
        $v = app(\App\Services\ClockVerifier::class);
        $today = now()->toDateString(); $time = now()->format('H:i:s');
        $row = DB::table('attendances')->where(['employee_id' => $e->id, 'date' => $today])->first();
        $hasGps = isset($d['lat'], $d['lng']);
        $place = $hasGps ? $v->nearest((float) $d['lat'], (float) $d['lng']) : null;
        $photo = $v->savePhoto($d['photo'] ?? null, $e->id, $dir);

        if ($dir === 'in') {
            if ($row && $row->timeclock_in) abort(422, 'Anda sudah clock-in hari ini pada ' . substr($row->timeclock_in, 0, 5) . '.');
            $flags = array_values(array_filter([
                !$hasGps ? 'no_gps' : null, $place && !$place['inside'] ? 'outside_fence' : null,
                $hasGps && ($d['accuracy'] ?? 0) > \App\Services\ClockVerifier::LOW_ACCURACY_M ? 'low_accuracy' : null, !$photo ? 'no_photo' : null]));
            $vals = ['status' => 'present', 'timeclock_in' => $time, 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'source' => 'ess', 'note' => $d['note'] ?? null,
                'photo_in' => $photo, 'accuracy_m' => isset($d['accuracy']) ? (int) round($d['accuracy']) : null,
                'in_place' => $place['code'] ?? null, 'in_distance_m' => $place['dist'] ?? null, 'in_fence' => $place['inside'] ?? null, 'updated_at' => now()];
            if ($row) DB::table('attendances')->where('id', $row->id)->update($vals);
            else $row = (object) ['id' => DB::table('attendances')->insertGetId($vals + ['employee_id' => $e->id, 'date' => $today, 'created_at' => now()])];

            // buddy-punching check: someone else clocked in at the same spot within minutes
            if ($hasGps && ($twins = $v->sameSpot($row->id, $e->id, $today, (float) $d['lat'], (float) $d['lng'], $time))) {
                $flags[] = 'same_spot';
                foreach ($twins as $t) {
                    $tf = json_decode(DB::table('attendances')->where('id', $t->id)->value('flags') ?? '[]', true) ?: [];
                    DB::table('attendances')->where('id', $t->id)->update(['flags' => json_encode(array_values(array_unique([...$tf, 'same_spot'])))]);
                }
                $who = implode(', ', array_map(fn ($t) => "{$t->name} ({$t->code}) {$t->t}", $twins));
                $this->ess->notifyMany($this->ess->approversFor('hr', $e), 'alert', "⚠ Clock-in mencurigakan: {$e->name}",
                    "Titik & masa hampir sama dengan $who", '/ess/hr?tab=alerts', 'fa-solid fa-user-secret');
            }
            DB::table('attendances')->where('id', $row->id)->update(['flags' => $flags ? json_encode($flags) : null]);
            if (in_array('outside_fence', $flags, true)) {
                $this->ess->notifyMany($this->ess->approversFor('supervisor', $e), 'alert', "📍 Clock-in di luar kawasan: {$e->name}",
                    "{$place['dist']} m dari {$place['code']} (had {$place['radius']} m) pada " . substr($time, 0, 5), '/ess/hr?tab=alerts', 'fa-solid fa-location-crosshairs');
            }
        } else {
            abort_unless($row && $row->timeclock_in, 422, 'Anda belum clock-in hari ini.');
            if ($row->timeclock_out) abort(422, 'Anda sudah clock-out pada ' . substr($row->timeclock_out, 0, 5) . '.');
            DB::table('attendances')->where('id', $row->id)->update(['timeclock_out' => $time, 'out_lat' => $d['lat'] ?? null, 'out_lng' => $d['lng'] ?? null,
                'photo_out' => $photo, 'out_place' => $place['code'] ?? null, 'out_distance_m' => $place['dist'] ?? null, 'out_fence' => $place['inside'] ?? null, 'updated_at' => now()]);
        }
        $att = DB::table('attendances')->where(['employee_id' => $e->id, 'date' => $today])->first();
        return (array) $att + ['place' => $place, 'flags' => json_decode($att->flags ?? '[]', true) ?: [],
            'photo_url' => \App\Services\ClockVerifier::photoUrl($dir === 'in' ? $att->photo_in : $att->photo_out)];
    }

    public function attendance(Request $r)
    {
        $e = $this->emp($r);
        $m = Carbon::parse(($r->input('month') ?: now()->format('Y-m')) . '-01');
        $rows = DB::table('attendances')->where('employee_id', $e->id)->whereBetween('date', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])
            ->orderBy('date')->get(['date', 'status', 'timeclock_in', 'timeclock_out', 'source', 'lat', 'lng']);
        $leaves = DB::table('ess_requests as r')->leftJoin('leave_types as t', 't.id', '=', 'r.leave_type_id')->where(['r.employee_id' => $e->id, 'r.type' => 'leave'])
            ->whereIn('r.status', ['approved', 'pending'])->where('r.start_date', '<=', $m->copy()->endOfMonth())->whereRaw('COALESCE(r.end_date,r.start_date) >= ?', [$m->toDateString()])
            ->get(['r.start_date', 'r.end_date', 'r.status', 't.name_ms', 't.color']);
        $hol = DB::table('public_holidays')->whereBetween('date', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])->get(['date', 'name']);
        return ['month' => $m->format('Y-m'), 'rows' => $rows, 'leaves' => $leaves, 'holidays' => $hol, 'workdays' => $e->workdays ?: 6];
    }

    /* ── requests: leave / overtime / timeoff / claim ─────────────────── */

    public function balances(Request $r) { return $this->ess->balances($this->emp($r)->id, (int) $r->input('year', now()->year)); }

    public function requests(Request $r)
    {
        $e = $this->emp($r);
        $q = DB::table('ess_requests as r')->leftJoin('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.employee_id', $e->id)
            ->select('r.*', 't.name_ms as leave_name', 't.icon as leave_icon', 't.color as leave_color');
        if ($t = $r->input('type')) $q->where('r.type', $t);
        $rows = $q->orderByDesc('r.id')->limit(200)->get();
        $steps = DB::table('ess_request_steps as s')->leftJoin('users as u', 'u.id', '=', 's.approver_user_id')->whereIn('s.ess_request_id', $rows->pluck('id'))
            ->orderBy('s.step')->get(['s.*', 'u.name as approver'])->groupBy('ess_request_id');
        return $rows->map(fn ($x) => (array) $x + ['details' => json_decode($x->details ?? 'null', true), 'steps' => $steps[$x->id] ?? []]);
    }

    public function store(Request $r, string $type)
    {
        abort_unless(in_array($type, ['leave', 'overtime', 'timeoff', 'claim'], true), 404);
        $e = $this->emp($r); $u = $r->user(); $lt = null; $details = []; $half = null;
        $d = $r->validate([
            'start_date' => 'required|date', 'end_date' => 'nullable|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i',
            'leave_type' => 'nullable|string', 'half_day' => 'nullable|in:am,pm', 'reason' => 'required|string|max:1000',
            'ot_type' => 'nullable|in:normal,rest,holiday', 'category' => 'nullable|string|max:40', 'amount' => 'nullable|numeric|min:0.01|max:100000',
            'attachment' => 'nullable|string|max:200',
        ]);
        $start = $d['start_date']; $end = $d['end_date'] ?? $start;
        switch ($type) {
            case 'leave':
                $lt = DB::table('leave_types')->where('code', $d['leave_type'] ?? '')->first();
                abort_unless($lt, 422, 'Sila pilih jenis cuti.');
                $half = $d['half_day'] ?? null;
                abort_if($half && $start !== $end, 422, 'Cuti separuh hari hanya untuk satu tarikh.');
                $qty = $this->ess->workingDays($start, $end, (int) ($e->workdays ?: 6), $half);
                abort_if($qty <= 0, 422, 'Tarikh dipilih ialah hari rehat / cuti umum — tiada hari bekerja.');
                $daysAhead = now()->startOfDay()->diffInDays(Carbon::parse($start), false);
                abort_if($daysAhead < -$lt->max_backdate_days, 422, "{$lt->name_ms} hanya boleh dimohon sehingga {$lt->max_backdate_days} hari ke belakang.");
                abort_if($lt->min_notice_days && $daysAhead < $lt->min_notice_days, 422, "{$lt->name_ms} perlu dimohon sekurang-kurangnya {$lt->min_notice_days} hari lebih awal.");
                abort_if($lt->requires_attachment && empty($d['attachment']), 422, "{$lt->name_ms} memerlukan dokumen sokongan (contoh: MC).");
                $overlap = DB::table('ess_requests')->where(['employee_id' => $e->id, 'type' => 'leave'])->whereIn('status', ['pending', 'approved'])
                    ->where('start_date', '<=', $end)->whereRaw('COALESCE(end_date,start_date) >= ?', [$start])->exists();
                abort_if($overlap, 422, 'Anda sudah ada permohonan cuti pada tarikh tersebut.');
                if ($lt->deducts_balance) {
                    $bal = collect($this->ess->balances($e->id, (int) substr($start, 0, 4)))->firstWhere('code', $lt->code === 'emergency' ? 'annual' : $lt->code);
                    abort_if($bal && $bal['available'] !== null && $qty > $bal['available'], 422, "Baki tidak mencukupi: {$bal['available']} hari tersedia, {$qty} hari dimohon.");
                }
                $unit = 'days';
                break;
            case 'overtime':
                abort_unless(!empty($d['start_time']) && !empty($d['end_time']), 422, 'Sila isi masa mula dan tamat OT.');
                $qty = $this->ess->hours($d['start_time'], $d['end_time']);
                abort_if($qty > 12, 422, 'Satu permohonan OT maksimum 12 jam.');
                $otType = $d['ot_type'] ?? 'normal';
                if (DB::table('public_holidays')->whereDate('date', $start)->exists()) $otType = 'holiday';
                $monthUsed = DB::table('ess_requests')->where(['employee_id' => $e->id, 'type' => 'overtime'])->whereIn('status', ['pending', 'approved'])
                    ->whereBetween('start_date', [Carbon::parse($start)->startOfMonth(), Carbon::parse($start)->endOfMonth()])->sum('quantity');
                abort_if($monthUsed + $qty > Ess::OT_CAP, 422, 'Melebihi had OT 104 jam sebulan (Akta Kerja 1955). Sudah direkod: ' . (float) $monthUsed . ' jam.');
                $details = ['ot_type' => $otType, 'rate' => Ess::OT_RATES[$otType]]; $end = $start; $unit = 'hours';
                break;
            case 'timeoff':
                abort_unless(!empty($d['start_time']) && !empty($d['end_time']), 422, 'Sila isi masa keluar dan masuk semula.');
                $qty = $this->ess->hours($d['start_time'], $d['end_time']);
                abort_if($qty > Ess::TIMEOFF_MAX_H, 422, 'Time-off maksimum ' . Ess::TIMEOFF_MAX_H . ' jam setiap slip — gunakan cuti separuh hari.');
                $n = DB::table('ess_requests')->where(['employee_id' => $e->id, 'type' => 'timeoff'])->whereIn('status', ['pending', 'approved'])
                    ->whereBetween('start_date', [Carbon::parse($start)->startOfMonth(), Carbon::parse($start)->endOfMonth()])->count();
                abort_if($n >= Ess::TIMEOFF_PER_MONTH, 422, 'Had ' . Ess::TIMEOFF_PER_MONTH . ' slip time-off sebulan telah dicapai.');
                $end = $start; $unit = 'hours';
                break;
            case 'claim':
                abort_unless(!empty($d['amount']) && !empty($d['category']), 422, 'Sila isi kategori dan jumlah tuntutan.');
                abort_if(now()->diffInDays(Carbon::parse($start), false) < -30, 422, 'Tuntutan mesti dihantar dalam 30 hari dari tarikh perbelanjaan.');
                $qty = (float) $d['amount']; $details = ['category' => $d['category']]; $unit = 'MYR';
                break;
        }
        if (!empty($d['attachment'])) $details['attachment'] = $d['attachment'];
        $chain = $this->ess->chainFor($type, $lt);

        $id = DB::transaction(function () use ($e, $u, $type, $lt, $start, $end, $d, $qty, $unit, $half, $details, $chain) {
            $id = DB::table('ess_requests')->insertGetId(['employee_id' => $e->id, 'user_id' => $u->id, 'type' => $type, 'leave_type_id' => $lt?->id,
                'start_date' => $start, 'end_date' => $end, 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null,
                'quantity' => $qty, 'unit' => $unit, 'half_day' => $half, 'reason' => $d['reason'], 'details' => json_encode($details),
                'status' => 'pending', 'current_step' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($chain as $i => $role) DB::table('ess_request_steps')->insert(['ess_request_id' => $id, 'step' => $i + 1, 'role' => $role,
                'action' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            return $id;
        });
        $label = Ess::LABEL[$type] . ($lt ? " ({$lt->name_ms})" : '');
        $this->ess->notifyMany($this->ess->approversFor($chain[0], $e, $u->id), 'approval', "Permohonan baharu: $label",
            "{$e->name} — " . $this->describe($type, $start, $end, $qty, $unit), '/ess/approvals', Ess::ICON[$type]);
        return ['id' => $id, 'quantity' => $qty, 'unit' => $unit];
    }

    private function describe(string $type, $start, $end, $qty, $unit): string
    {
        $f = fn ($x) => Carbon::parse($x)->format('d M');
        return match ($unit) { 'days' => "{$f($start)}" . ($end && $end !== $start ? " – {$f($end)}" : '') . " ({$qty} hari)",
            'hours' => "{$f($start)}, {$qty} jam", default => 'RM ' . number_format((float) $qty, 2) . " ({$f($start)})" };
    }

    public function cancel(Request $r, $id)
    {
        $e = $this->emp($r);
        $req = DB::table('ess_requests')->where(['id' => $id, 'employee_id' => $e->id])->first();
        abort_unless($req, 404);
        abort_unless(in_array($req->status, ['pending', 'approved']), 422, 'Permohonan ini tidak boleh dibatalkan.');
        abort_if($req->status === 'approved' && $req->start_date < now()->toDateString(), 422, 'Permohonan yang telah lulus dan bermula tidak boleh dibatalkan — hubungi HR.');
        if ($req->status === 'approved') $this->ess->applyLeaveBalance($req, -1);
        DB::table('ess_requests')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        return ['ok' => true];
    }

    /* ── approvals ────────────────────────────────────────────────────── */

    private function approvalQueue(Request $r): array
    {
        $u = $r->user();
        $rows = DB::table('ess_requests as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->leftJoin('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('ess_request_steps as s', fn ($j) => $j->on('s.ess_request_id', '=', 'r.id')->on('s.step', '=', 'r.current_step'))
            ->where('r.status', 'pending')->orderBy('r.created_at')
            ->get(['r.*', 'e.name as employee', 'e.employee_id as code', 'e.job_level', 'e.hq_region', 't.name_ms as leave_name', 't.icon as leave_icon', 't.color as leave_color',
                's.id as step_id', 's.role as step_role', 's.step as step_no']);
        return $rows->filter(fn ($x) => $this->ess->canAct($u, $x, (object) ['role' => $x->step_role]))
            ->map(fn ($x) => (array) $x + ['details' => json_decode($x->details ?? 'null', true), 'office' => $this->ess->office($x->hq_region),
                'steps' => DB::table('ess_request_steps as s')->leftJoin('users as u', 'u.id', '=', 's.approver_user_id')->where('ess_request_id', $x->id)->orderBy('step')->get(['s.*', 'u.name as approver']),
                'balance' => $x->type === 'leave' ? collect($this->ess->balances($x->employee_id, (int) substr($x->start_date, 0, 4)))->firstWhere('id', $x->leave_type_id) : null])
            ->values()->all();
    }

    public function approvals(Request $r)
    {
        abort_unless($this->roles($r)['approver'], 403);
        $done = DB::table('ess_request_steps as s')->join('ess_requests as r', 'r.id', '=', 's.ess_request_id')->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->leftJoin('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('s.approver_user_id', $r->user()->id)->orderByDesc('s.acted_at')->limit(30)
            ->get(['r.id', 'r.type', 'r.start_date', 'r.end_date', 'r.quantity', 'r.unit', 'r.status', 'e.name as employee', 't.name_ms as leave_name', 's.action', 's.acted_at', 's.comment']);
        return ['pending' => $this->approvalQueue($r), 'history' => $done];
    }

    public function act(Request $r, $id)
    {
        $d = $r->validate(['action' => 'required|in:approve,reject', 'comment' => 'nullable|string|max:500']);
        abort_if($d['action'] === 'reject' && empty($d['comment']), 422, 'Sila nyatakan sebab penolakan.');
        $u = $r->user();
        $req = DB::table('ess_requests')->where('id', $id)->first();
        abort_unless($req && $req->status === 'pending', 422, 'Permohonan ini sudah diproses.');
        $step = DB::table('ess_request_steps')->where(['ess_request_id' => $id, 'step' => $req->current_step])->first();
        abort_unless($this->ess->canAct($u, $req, $step), 403, 'Anda tidak boleh meluluskan peringkat ini.');
        $emp = DB::table('employees')->find($req->employee_id);
        $label = Ess::LABEL[$req->type];

        DB::transaction(function () use ($d, $u, $req, $step, $emp, $label) {
            DB::table('ess_request_steps')->where('id', $step->id)->update(['action' => $d['action'] === 'approve' ? 'approved' : 'rejected',
                'approver_user_id' => $u->id, 'comment' => $d['comment'] ?? null, 'acted_at' => now(), 'updated_at' => now()]);
            if ($d['action'] === 'reject') {
                DB::table('ess_requests')->where('id', $req->id)->update(['status' => 'rejected', 'updated_at' => now()]);
                DB::table('ess_request_steps')->where('ess_request_id', $req->id)->where('step', '>', $req->current_step)->update(['action' => 'skipped']);
                if ($req->user_id) $this->ess->notify($req->user_id, 'rejected', "$label anda ditolak", "Oleh {$u->name}: {$d['comment']}", '/ess/requests', 'fa-solid fa-circle-xmark');
                return;
            }
            $next = DB::table('ess_request_steps')->where(['ess_request_id' => $req->id, 'step' => $req->current_step + 1])->first();
            if ($next) {
                DB::table('ess_requests')->where('id', $req->id)->update(['current_step' => $next->step, 'updated_at' => now()]);
                $this->ess->notifyMany($this->ess->approversFor($next->role, $emp, $req->user_id), 'approval', "Menunggu kelulusan peringkat {$next->step}: $label",
                    "{$emp->name} — diluluskan peringkat {$step->step} oleh {$u->name}", '/ess/approvals', 'fa-solid fa-stamp');
                if ($req->user_id) $this->ess->notify($req->user_id, 'progress', "$label anda lulus peringkat {$step->step}", "Seterusnya: " . ($next->role === 'hr' ? 'HR' : 'Penyelia'), '/ess/requests', 'fa-solid fa-forward');
            } else {
                DB::table('ess_requests')->where('id', $req->id)->update(['status' => 'approved', 'updated_at' => now()]);
                $this->ess->applyLeaveBalance($req, 1);
                if ($req->user_id) $this->ess->notify($req->user_id, 'approved', "$label anda telah diluluskan", "Diluluskan oleh {$u->name}", '/ess/requests', 'fa-solid fa-circle-check');
            }
        });
        return ['ok' => true];
    }

    /* ── notifications ────────────────────────────────────────────────── */

    public function notifications(Request $r)
    {
        return DB::table('ess_notifications')->where('user_id', $r->user()->id)->orderByDesc('id')->limit(100)->get();
    }

    public function readNotifications(Request $r)
    {
        $q = DB::table('ess_notifications')->where('user_id', $r->user()->id)->whereNull('read_at');
        if ($id = $r->input('id')) $q->where('id', $id);
        return ['updated' => $q->update(['read_at' => now()])];
    }

    /* ── memos ────────────────────────────────────────────────────────── */

    private function memoQuery(int $uid)
    {
        return DB::table('memos as m')->leftJoin('memo_reads as mr', fn ($j) => $j->on('mr.memo_id', '=', 'm.id')->where('mr.user_id', $uid))
            ->leftJoin('users as a', 'a.id', '=', 'm.author_user_id')->whereNotNull('m.published_at')->where('m.published_at', '<=', now())
            ->orderByDesc('m.pinned')->orderByDesc('m.published_at')
            ->select('m.id', 'm.title', 'm.category', 'm.priority', 'm.pinned', 'm.requires_ack', 'm.published_at', 'a.name as author',
                DB::raw('left(m.body, 220) as excerpt'), 'mr.read_at', 'mr.acked_at');
    }

    public function memos(Request $r)
    {
        $uid = $r->user()->id;
        $rows = $this->memoQuery($uid)->get();
        $total = DB::table('users')->whereNotNull('employee_id')->count();
        $stats = DB::table('memo_reads')->selectRaw('memo_id, count(read_at) reads, count(acked_at) acks')->groupBy('memo_id')->get()->keyBy('memo_id');
        return $rows->map(fn ($m) => (array) $m + ['reads' => (int) ($stats[$m->id]->reads ?? 0), 'acks' => (int) ($stats[$m->id]->acks ?? 0), 'audience' => $total]);
    }

    public function memo(Request $r, $id)
    {
        $m = DB::table('memos as m')->leftJoin('users as a', 'a.id', '=', 'm.author_user_id')->where('m.id', $id)->first(['m.*', 'a.name as author']);
        abort_unless($m, 404);
        $key = ['memo_id' => $id, 'user_id' => $r->user()->id];
        if (DB::table('memo_reads')->where($key)->exists()) DB::table('memo_reads')->where($key)->whereNull('read_at')->update(['read_at' => now()]);
        else DB::table('memo_reads')->insert($key + ['read_at' => now()]);
        return (array) $m + ['acked_at' => DB::table('memo_reads')->where(['memo_id' => $id, 'user_id' => $r->user()->id])->value('acked_at')];
    }

    public function ackMemo(Request $r, $id)
    {
        DB::table('memo_reads')->updateOrInsert(['memo_id' => $id, 'user_id' => $r->user()->id], ['read_at' => now(), 'acked_at' => now()]);
        return ['ok' => true];
    }

    public function storeMemo(Request $r)
    {
        abort_unless($this->roles($r)['hr'], 403, 'Hanya HR / pengurusan boleh menerbitkan memo.');
        $d = $r->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:20000', 'category' => 'required|in:general,hr,finance,safety,operations,it',
            'priority' => 'required|in:normal,important,urgent', 'pinned' => 'boolean', 'requires_ack' => 'boolean']);
        $id = DB::table('memos')->insertGetId($d + ['author_user_id' => $r->user()->id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $staff = DB::table('users')->whereNotNull('employee_id')->where('id', '<>', $r->user()->id)->get(['id']);
        $this->ess->notifyMany($staff, 'memo', ($d['priority'] === 'urgent' ? '🔴 ' : '') . "Memo: {$d['title']}", mb_substr($d['body'], 0, 120), '/ess/memos', 'fa-solid fa-bullhorn');
        return ['id' => $id];
    }

    /* ── account ──────────────────────────────────────────────────────── */

    public function password(Request $r)
    {
        $d = $r->validate(['current' => 'required|string', 'password' => 'required|string|min:8|confirmed']);
        abort_unless(\Illuminate\Support\Facades\Hash::check($d['current'], $r->user()->password), 422, 'Kata laluan semasa tidak betul.');
        abort_if($d['password'] === 'password', 422, 'Sila pilih kata laluan selain kata laluan demo.');
        $r->user()->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($d['password'])])->save();
        return ['ok' => true];
    }

    /** Plain-text summary of the signed-in employee's own HR data, used as private context for the Ask assistant. */
    public function personalContext(\App\Models\User $u): ?string
    {
        $e = $this->ess->employeeFor($u); if (!$e) return null;
        $req = new Request(); $req->setUserResolver(fn () => $u);
        $h = $this->home($req);
        $bal = collect($h['balances'])->filter(fn ($b) => $b['entitled'] !== null)->map(fn ($b) => "{$b['name_ms']}: entitled {$b['entitled']}, used {$b['used']}, pending {$b['pending']}, available {$b['available']} days")->implode("\n- ");
        $pend = collect($h['my_pending'])->map(fn ($p) => Ess::LABEL[$p->type] . " #{$p->id} from {$p->start_date}: {$p->quantity} {$p->unit}, waiting step {$p->current_step}")->implode("\n- ");
        $hol = collect($h['holidays'])->map(fn ($x) => substr($x->date, 0, 10) . " {$x->name}")->implode(', ');
        return "Employee: {$e->name} ({$e->employee_id}), role {$e->job_level}, office {$h['employee']['office']}, works {$h['employee']['workdays']} days a week.\n"
            . "Today: " . ($h['today']?->timeclock_in ? 'clocked in at ' . substr($h['today']->timeclock_in, 0, 5) . ($h['today']->timeclock_out ? ', out at ' . substr($h['today']->timeclock_out, 0, 5) : ', not clocked out yet') : 'not clocked in yet') . ".\n"
            . "This month: {$h['month']['present']} days present, {$h['month']['late']} late arrivals (after 08:30), {$h['month']['hours']} hours worked, average clock-in {$h['month']['avg_in']}, {$h['month']['missing_out']} days without clock-out.\n"
            . "Overtime this month: {$h['ot']['hours']} of {$h['ot']['cap']} hours allowed. Time-off slips this month: {$h['timeoff']['used']} of {$h['timeoff']['limit']}.\n"
            . "Leave balances " . now()->year . ":\n- $bal\n"
            . "Pending requests:\n- " . ($pend ?: 'none') . "\n"
            . "Upcoming public holidays: " . ($hol ?: 'none listed') . ". Emergency leave is deducted from annual leave.";
    }

    /* ── holidays, team, reports ──────────────────────────────────────── */

    public function holidays(Request $r)
    {
        return DB::table('public_holidays')->whereYear('date', (int) $r->input('year', now()->year))->orderBy('date')->get();
    }

    public function team(Request $r)
    {
        $e = $this->emp($r);
        $from = $r->input('from', now()->startOfMonth()->toDateString()); $to = $r->input('to', now()->endOfMonth()->toDateString());
        return ['office' => $this->ess->office($e->hq_region), 'leaves' => $this->teamOut($e, $from, $to)];
    }

    public function report(Request $r)
    {
        $e = $this->emp($r); $year = (int) $r->input('year', now()->year);
        $att = DB::table('attendances')->where('employee_id', $e->id)->whereYear('date', $year)
            ->selectRaw("extract(month from date)::int m, count(*) FILTER (WHERE status IN ('present','late')) present,
                count(*) FILTER (WHERE status='on_leave') leave, count(*) FILTER (WHERE status='absent') absent,
                count(*) FILTER (WHERE timeclock_in > '08:30' AND timeclock_in < '12:00') late,
                round(sum(CASE WHEN timeclock_out > timeclock_in THEN extract(epoch FROM timeclock_out - timeclock_in)/3600 END)::numeric,1) hours,
                count(*) FILTER (WHERE timeclock_in IS NOT NULL AND timeclock_out IS NULL) no_out")
            ->groupBy('m')->get()->keyBy('m');
        $req = DB::table('ess_requests')->where(['employee_id' => $e->id, 'status' => 'approved'])->whereYear('start_date', $year)
            ->selectRaw("extract(month from start_date)::int m, type, sum(quantity) q")->groupBy('m', 'type')->get()->groupBy('m');
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $a = $att[$m] ?? null; $q = collect($req[$m] ?? [])->keyBy('type');
            $months[] = ['month' => $m, 'present' => (int) ($a->present ?? 0), 'leave_days_att' => (int) ($a->leave ?? 0), 'absent' => (int) ($a->absent ?? 0),
                'late' => (int) ($a->late ?? 0), 'hours' => (float) ($a->hours ?? 0), 'no_out' => (int) ($a->no_out ?? 0),
                'leave' => (float) ($q['leave']->q ?? 0), 'ot' => (float) ($q['overtime']->q ?? 0), 'timeoff' => (float) ($q['timeoff']->q ?? 0), 'claims' => (float) ($q['claim']->q ?? 0)];
        }
        return ['year' => $year, 'employee' => ['code' => $e->employee_id, 'name' => $e->name], 'months' => $months, 'balances' => $this->ess->balances($e->id, $year)];
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/** Employee self-service rules: working-day maths, approval chains, notifications. */
class Ess
{
    public const OT_CAP = 104;          // Employment Act: max overtime hours per month
    public const TIMEOFF_MAX_H = 4;     // TEMPLATE company rule
    public const TIMEOFF_PER_MONTH = 2; // TEMPLATE company rule
    public const OT_RATES = ['normal' => 1.5, 'rest' => 2.0, 'holiday' => 3.0];

    public function employeeFor(?User $u): ?object
    {
        if (!$u || !$u->employee_id) return null;
        return DB::table('employees')->where('employee_id', $u->employee_id)->first();
    }

    public function office(?string $r): string { return match ($r) { 'northern' => 'PSU', 'southern' => 'PSS', default => 'HQ' }; }

    /** Restrict a query to employees of the same office ("hq" and empty both mean HQ). */
    public function sameOffice($q, string $col, ?string $region)
    {
        return in_array($region, ['northern', 'southern'], true)
            ? $q->where($col, $region)
            : $q->where(fn ($w) => $w->whereNull($col)->orWhereNotIn($col, ['northern', 'southern']));
    }

    /** Working days between two dates, skipping rest days (Sunday; Saturday too for 5-day staff) and public holidays. */
    public function workingDays(string $from, string $to, int $workdays = 6, ?string $half = null): float
    {
        $hol = DB::table('public_holidays')->whereBetween('date', [$from, $to])->pluck('date')->map(fn ($d) => substr($d, 0, 10))->all();
        $n = 0;
        foreach (CarbonPeriod::create($from, $to) as $d) {
            if ($d->isSunday() || ($workdays <= 5 && $d->isSaturday()) || in_array($d->toDateString(), $hol, true)) continue;
            $n++;
        }
        return $half && $from === $to ? min($n, 0.5) : (float) $n;
    }

    public function chainFor(string $type, ?object $leaveType): array
    {
        return match ($type) {
            'leave' => json_decode($leaveType->approval_chain, true),
            'overtime', 'claim' => ['supervisor', 'hr'],
            default => ['supervisor'],    // timeoff
        };
    }

    /** Who gets NOTIFIED for a step: supervisors of the requester's office for "supervisor" (HR only if that office has
     *  none), HR staff for "hr". HR can still act on any step (see canAct) — they just aren't pinged for every request. */
    public function approversFor(string $role, object $emp, ?int $exceptUserId = null)
    {
        $base = fn () => User::query()->where('blocked', false)->when($exceptUserId, fn ($q) => $q->where('id', '<>', $exceptUserId));
        $hr = fn () => $base()->whereHas('roles', fn ($r) => $r->where('name', 'ess_hr'))->whereNotNull('employee_id')->get()
            ->whenEmpty(fn () => $base()->whereHas('roles', fn ($r) => $r->where('name', 'ess_hr'))->get());   // fall back to admins
        if ($role !== 'supervisor') return $hr();
        $sup = $base()->whereHas('roles', fn ($r) => $r->where('name', 'ess_supervisor'))
            ->whereIn('employee_id', $this->sameOffice(DB::table('employees'), 'hq_region', $emp->hq_region)->pluck('employee_id'))->get();
        return $sup->isNotEmpty() ? $sup : $hr();
    }

    public function canAct(User $u, object $req, object $step): bool
    {
        if ($req->user_id === $u->id) return false;                       // never approve your own request
        if ($u->hasRole('ess_hr') || $u->hasRole('super_admin')) return true;
        if ($step->role !== 'supervisor' || !$u->hasRole('ess_supervisor')) return false;
        $me = $this->employeeFor($u); $them = DB::table('employees')->where('id', $req->employee_id)->first();
        return $me && $them && $this->office($me->hq_region) === $this->office($them->hq_region);
    }

    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $icon = null): void
    {
        DB::table('ess_notifications')->insert(['user_id' => $userId, 'type' => $type, 'icon' => $icon, 'title' => $title, 'body' => $body,
            'link' => $link, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function notifyMany($users, string $type, string $title, ?string $body = null, ?string $link = null, ?string $icon = null): void
    {
        $rows = collect($users)->unique('id')->map(fn ($u) => ['user_id' => $u->id, 'type' => $type, 'icon' => $icon, 'title' => $title, 'body' => $body,
            'link' => $link, 'created_at' => now(), 'updated_at' => now()])->values()->all();
        foreach (array_chunk($rows, 200) as $c) DB::table('ess_notifications')->insert($c);
    }

    public const LABEL = ['leave' => 'Cuti', 'overtime' => 'Kerja lebih masa', 'timeoff' => 'Time-off', 'claim' => 'Tuntutan'];
    public const ICON = ['leave' => 'fa-solid fa-umbrella-beach', 'overtime' => 'fa-solid fa-business-time', 'timeoff' => 'fa-solid fa-person-walking-arrow-right', 'claim' => 'fa-solid fa-receipt'];

    /** Apply approval to a balance when a leave request is fully approved (emergency leave draws on annual). */
    public function applyLeaveBalance(object $req, int $sign = 1): void
    {
        if ($req->type !== 'leave') return;
        $lt = DB::table('leave_types')->find($req->leave_type_id);
        if (!$lt || !$lt->deducts_balance) return;
        $code = $lt->code === 'emergency' ? 'annual' : $lt->code;
        $typeId = DB::table('leave_types')->where('code', $code)->value('id');
        DB::table('leave_balances')->where(['employee_id' => $req->employee_id, 'leave_type_id' => $typeId, 'year' => (int) substr($req->start_date, 0, 4)])
            ->update(['used' => DB::raw('used + ' . ((float) $req->quantity * $sign)), 'updated_at' => now()]);
    }

    /** Days already requested (pending) or used against a balance — for "available" figures. */
    public function balances(int $empId, int $year): array
    {
        $types = DB::table('leave_types')->orderBy('sort')->get();
        $bal = DB::table('leave_balances')->where(['employee_id' => $empId, 'year' => $year])->get()->keyBy('leave_type_id');
        $pending = DB::table('ess_requests as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where(['r.employee_id' => $empId, 'r.type' => 'leave', 'r.status' => 'pending'])->whereYear('r.start_date', $year)
            ->selectRaw("CASE WHEN t.code='emergency' THEN 'annual' ELSE t.code END code, sum(r.quantity) q")->groupBy('code')->pluck('q', 'code');
        return $types->map(function ($t) use ($bal, $pending) {
            $b = $bal[$t->id] ?? null;
            $total = $b ? (float) $b->entitled + (float) $b->carried : null;
            $used = $b ? (float) $b->used : 0;
            $pend = (float) ($pending[$t->code] ?? 0);
            return ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'name_ms' => $t->name_ms, 'icon' => $t->icon, 'color' => $t->color,
                'requires_attachment' => (bool) $t->requires_attachment, 'min_notice_days' => $t->min_notice_days, 'max_backdate_days' => $t->max_backdate_days,
                'chain' => json_decode($t->approval_chain, true), 'deducts' => (bool) $t->deducts_balance, 'uses' => $t->code === 'emergency' ? 'annual' : $t->code,
                'entitled' => $total, 'used' => $used, 'pending' => $pend, 'available' => $total !== null ? round($total - $used - $pend, 1) : null];
        })->values()->all();
    }

    public function hours(string $start, string $end): float
    {
        $s = Carbon::parse($start); $e = Carbon::parse($end);
        if ($e->lte($s)) $e->addDay();   // overnight
        return round($s->diffInMinutes($e) / 60, 2);
    }
}

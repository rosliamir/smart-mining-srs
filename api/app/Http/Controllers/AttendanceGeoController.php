<?php

namespace App\Http\Controllers;

use App\Services\Ess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attendance location & time analytics.
 *  - self:  /api/ess/me/trend, /api/ess/me/locations      (signed-in employee only)
 *  - HR:    /api/hr/attendance/map|heatmap|trend          (ess_hr, super_admin, manager, executive)
 *
 * Location per record: the GPS fix captured by ESS clock-in/out when present; otherwise the coordinates of the
 * site the legacy system linked the attendance to ("site" precision). Records with neither have no location.
 */
class AttendanceGeoController extends Controller
{
    private const LATE = '08:30:00';
    private const FENCE_M = 1000;   // GPS further than this from every site is flagged "outside"

    public function __construct(private Ess $ess) {}

    /** HR / management see every office; ESS supervisors are limited to their own office. Returns the forced office or null. */
    private function hrOnly(Request $r): ?string
    {
        $u = $r->user();
        if ($u->hasAnyRole(['ess_hr', 'super_admin', 'manager', 'executive'])) return null;
        abort_unless($u->hasRole('ess_supervisor'), 403, 'HR / management only.');
        return $this->ess->office($this->ess->employeeFor($u)?->hq_region);
    }
    private function officeFor(Request $r): ?string { return $this->hrOnly($r) ?? $r->input('office'); }

    private function range(Request $r, int $defDays = 30): array
    {
        $to = Carbon::parse($r->input('to', now()->toDateString()));
        $from = Carbon::parse($r->input('from', $to->copy()->subDays($defDays - 1)->toDateString()));
        return [$from->toDateString(), $to->toDateString()];
    }

    /** Base rows: one per attendance with resolved location + nearest site for GPS fixes. */
    private function rows(string $from, string $to, ?int $employeeId = null, ?string $office = null): array
    {
        $b = [$from, $to]; $w = '';
        if ($employeeId) { $w .= ' AND a.employee_id = ?'; $b[] = $employeeId; }
        if ($office) {
            $w .= match ($office) { 'PSU' => " AND e.hq_region = 'northern'", 'PSS' => " AND e.hq_region = 'southern'",
                default => " AND (e.hq_region IS NULL OR e.hq_region NOT IN ('northern','southern'))" };
        }
        $sql = "SELECT a.id, a.date::text date, a.status, to_char(a.timeclock_in,'HH24:MI') tin, to_char(a.timeclock_out,'HH24:MI') tout,
                  extract(epoch FROM a.timeclock_in)/3600 in_h, extract(epoch FROM a.timeclock_out)/3600 out_h,
                  a.source, e.id emp_id, e.employee_id code, e.name, e.job_level role,
                  CASE e.hq_region WHEN 'northern' THEN 'PSU' WHEN 'southern' THEN 'PSS' ELSE 'HQ' END office,
                  a.lat::float glat, a.lng::float glng, a.out_lat::float olat, a.out_lng::float olng,
                  s.site_code, ST_Y(s.geom) slat, ST_X(s.geom) slng,
                  near.site_code near_site, near.dist near_m,
                  a.in_place, a.in_distance_m, a.in_fence, a.out_place, a.out_fence, a.flags, a.photo_in, a.photo_out, a.accuracy_m, a.reviewed_at, a.review_note
                FROM attendances a JOIN employees e ON e.id = a.employee_id
                LEFT JOIN sites s ON s.id = a.site_id
                LEFT JOIN LATERAL (
                  SELECT s2.site_code, round(ST_Distance(s2.geom::geography, ST_SetSRID(ST_MakePoint(a.lng, a.lat), 4326)::geography)) dist
                  FROM sites s2 WHERE a.lat IS NOT NULL AND a.lng IS NOT NULL AND s2.geom IS NOT NULL
                  ORDER BY s2.geom <-> ST_SetSRID(ST_MakePoint(a.lng, a.lat), 4326) LIMIT 1) near ON true
                WHERE a.date BETWEEN ? AND ? $w ORDER BY a.date, a.timeclock_in NULLS LAST";
        return array_map(function ($r) {
            $r = (array) $r;
            $gps = $r['glat'] !== null && $r['glng'] !== null;
            $place = $r['in_place'] ?? $r['near_site']; $dist = $r['in_distance_m'] ?? $r['near_m'];
            $r['loc'] = $gps ? ['lat' => $r['glat'], 'lng' => $r['glng'], 'precision' => 'gps', 'label' => $place ? "≈ {$dist} m dari {$place}" : 'GPS']
                : ($r['slat'] !== null ? ['lat' => (float) $r['slat'], 'lng' => (float) $r['slng'], 'precision' => 'site', 'label' => "Tapak {$r['site_code']}"] : null);
            $r['out_loc'] = $r['olat'] !== null ? ['lat' => $r['olat'], 'lng' => $r['olng']] : null;
            // per-location radius decided at clock-in time; older GPS rows fall back to the default fence
            $r['outside'] = $gps && ($r['in_fence'] !== null ? !$r['in_fence'] : ($r['near_m'] !== null && $r['near_m'] > self::FENCE_M));
            $r['flags'] = json_decode($r['flags'] ?? '[]', true) ?: [];
            $r['photo_in'] = \App\Services\ClockVerifier::photoUrl($r['photo_in']);
            $r['photo_out'] = \App\Services\ClockVerifier::photoUrl($r['photo_out']);
            $r['late'] = $r['tin'] && $r['tin'] > substr(self::LATE, 0, 5) && $r['tin'] < '12:00';
            $r['in_h'] = $r['in_h'] !== null ? round((float) $r['in_h'], 3) : null;
            $r['out_h'] = $r['out_h'] !== null ? round((float) $r['out_h'], 3) : null;
            unset($r['glat'], $r['glng'], $r['olat'], $r['olng'], $r['slat'], $r['slng']);
            return $r;
        }, DB::select($sql, $b));
    }

    /** Daily in/out series + weekly averages for the line chart. */
    private function trendFrom(array $rows): array
    {
        $daily = collect($rows)->filter(fn ($r) => $r['in_h'] !== null && $r['in_h'] < 14)->groupBy('date')->map(fn ($g, $d) => [
            'date' => $d, 'in' => round($g->avg('in_h'), 3), 'out' => ($o = $g->whereNotNull('out_h')->avg('out_h')) ? round($o, 3) : null,
            'hours' => round($g->filter(fn ($x) => $x['out_h'] > $x['in_h'])->avg(fn ($x) => $x['out_h'] - $x['in_h']) ?? 0, 2),
            'late' => $g->where('late', true)->count(), 'n' => $g->count()])->values();
        $weekly = $daily->groupBy(fn ($d) => Carbon::parse($d['date'])->startOfWeek()->toDateString())->map(fn ($g, $wk) => [
            'week' => $wk, 'in' => round($g->avg('in'), 3), 'out' => ($o = $g->whereNotNull('out')->avg('out')) ? round($o, 3) : null,
            'hours' => round($g->avg('hours'), 2), 'late' => $g->sum('late'), 'days' => $g->count()])->values();
        return ['daily' => $daily, 'weekly' => $weekly, 'late_line' => 8.5];
    }

    /* ── self ─────────────────────────────────────────────────────────── */

    private function me(Request $r): object
    {
        $e = $this->ess->employeeFor($r->user());
        abort_unless($e, 403, 'Akaun ini tidak dipautkan kepada rekod pekerja.');
        return $e;
    }

    public function myTrend(Request $r)
    {
        $e = $this->me($r);
        $days = ['week' => 7, 'month' => 31, 'quarter' => 92][$r->input('period', 'month')] ?? 31;
        [$from, $to] = $this->range($r, $days);
        return ['from' => $from, 'to' => $to] + $this->trendFrom($this->rows($from, $to, $e->id));
    }

    public function myLocations(Request $r)
    {
        $e = $this->me($r);
        [$from, $to] = $this->range($r, 31);
        $rows = array_values(array_filter($this->rows($from, $to, $e->id), fn ($x) => $x['loc']));
        return ['from' => $from, 'to' => $to, 'points' => $rows, 'sites' => $this->sitesUsed($rows)];
    }

    private function sitesUsed(array $rows): array
    {
        $codes = collect($rows)->pluck('site_code')->merge(collect($rows)->pluck('near_site'))->filter()->unique()->values();
        if ($codes->isEmpty()) return [];
        return DB::table('sites')->whereIn('site_code', $codes)->whereNotNull('geom')
            ->selectRaw('site_code, name, ST_Y(geom) lat, ST_X(geom) lng, geofence_m radius')->get()->all();
    }

    /* ── HR / management ──────────────────────────────────────────────── */

    public function hrMap(Request $r)
    {
        $office = $this->officeFor($r);
        [$from, $to] = $this->range($r, 1);
        $rows = $this->rows($from, $to, $r->integer('employee') ?: null, $office);
        $located = array_values(array_filter($rows, fn ($x) => $x['loc']));
        $bySite = collect($located)->groupBy(fn ($x) => $x['site_code'] ?? $x['near_site'] ?? 'GPS')->map->count()->sortDesc();
        return [
            'from' => $from, 'to' => $to,
            'points' => $located,
            'sites' => DB::table('sites')->whereNotNull('geom')->selectRaw('site_code, name, ST_Y(geom) lat, ST_X(geom) lng, geofence_m radius')->get(),
            'offices' => DB::table('work_locations')->where('active', true)->whereNotNull('lat')->get(['code', 'name', 'lat', 'lng', 'radius_m as radius']),
            'summary' => ['records' => count($rows), 'located' => count($located),
                'gps' => collect($located)->where('loc.precision', 'gps')->count(), 'site' => collect($located)->where('loc.precision', 'site')->count(),
                'outside' => collect($located)->where('outside', true)->count(), 'late' => collect($rows)->where('late', true)->count(),
                'no_location' => count($rows) - count($located), 'by_site' => $bySite->take(12)],
            'fence_m' => self::FENCE_M,
        ];
    }

    public function hrHeatmap(Request $r)
    {
        $office = $this->officeFor($r);
        [$from, $to] = $this->range($r, (int) $r->input('days', 60));
        $rows = collect($this->rows($from, $to, null, $office));
        $timed = $rows->filter(fn ($x) => $x['in_h'] !== null && $x['in_h'] >= 5 && $x['in_h'] < 12);

        // 1. arrival heatmap: weekday x 15-minute slot (05:00–11:45)
        $slots = []; for ($m = 300; $m < 720; $m += 15) $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $dow = ['Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab', 'Ahd'];
        $arr = array_fill(0, 7, array_fill(0, count($slots), 0));
        foreach ($timed as $x) { $d = (Carbon::parse($x['date'])->dayOfWeekIso - 1); $s = (int) floor(($x['in_h'] * 60 - 300) / 15); if ($s >= 0 && $s < count($slots)) $arr[$d][$s]++; }

        // 2. departure heatmap: weekday x 30-minute slot (12:00–23:30)
        $oslots = []; for ($m = 720; $m < 1440; $m += 30) $oslots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $dep = array_fill(0, 7, array_fill(0, count($oslots), 0));
        foreach ($rows->filter(fn ($x) => $x['out_h'] !== null && $x['out_h'] >= 12) as $x) { $d = (Carbon::parse($x['date'])->dayOfWeekIso - 1); $s = (int) floor(($x['out_h'] * 60 - 720) / 30); if ($s >= 0 && $s < count($oslots)) $dep[$d][$s]++; }

        // 3. calendar: per day attendance + late rate
        $cal = $rows->groupBy('date')->map(fn ($g, $d) => ['date' => $d, 'present' => $g->filter(fn ($x) => $x['tin'])->count(), 'records' => $g->count(),
            'leave' => $g->where('status', 'on_leave')->count(), 'late' => $g->where('late', true)->count(),
            'late_rate' => ($p = $g->filter(fn ($x) => $x['tin'] && $x['in_h'] < 12)->count()) ? round($g->where('late', true)->count() / $p * 100) : 0])->values();

        // 4. punctuality matrix: top staff by late count x date (minutes late, 0 = on time, null = no record)
        $dates = $rows->pluck('date')->unique()->sort()->values();
        $top = $timed->where('late', true)->groupBy('emp_id')->sortByDesc(fn ($g) => $g->count())->take(20);
        $matrix = $top->map(function ($g, $empId) use ($timed, $dates) {
            $mine = $timed->where('emp_id', $empId)->keyBy('date');
            $f = $g->first();
            return ['emp_id' => $empId, 'name' => $f['name'], 'code' => $f['code'], 'office' => $f['office'], 'late' => $g->count(),
                'cells' => $dates->map(fn ($d) => isset($mine[$d]) ? max(0, (int) round(($mine[$d]['in_h'] - 8.5) * 60)) : null)->values()];
        })->values();

        // 5. site density (where people clock in)
        $sites = $rows->filter(fn ($x) => $x['loc'])->groupBy(fn ($x) => $x['site_code'] ?? $x['near_site'] ?? 'GPS')
            ->map(fn ($g, $code) => ['site_code' => $code, 'lat' => $g->first()['loc']['lat'], 'lng' => $g->first()['loc']['lng'], 'count' => $g->count(),
                'staff' => $g->pluck('emp_id')->unique()->count(), 'late' => $g->where('late', true)->count()])->sortByDesc('count')->values();

        return ['from' => $from, 'to' => $to, 'slots' => $slots, 'dow' => $dow, 'arrival' => $arr, 'out_slots' => $oslots, 'departure' => $dep,
            'calendar' => $cal, 'dates' => $dates, 'matrix' => $matrix, 'sites' => $sites] + ['trend' => $this->trendFrom($rows->all())];
    }

    /** Team (or one employee) in/out trend for HR. */
    public function hrTrend(Request $r)
    {
        $office = $this->officeFor($r);
        $days = ['week' => 7, 'month' => 31, 'quarter' => 92][$r->input('period', 'month')] ?? 31;
        [$from, $to] = $this->range($r, $days);
        $rows = $this->rows($from, $to, $r->integer('employee') ?: null, $office);
        return ['from' => $from, 'to' => $to] + $this->trendFrom($rows);
    }

    public function staffList(Request $r)
    {
        $forced = $this->hrOnly($r);
        $list = DB::table('employees')->whereNotNull('employee_id')->orderBy('name')
            ->get(['id', 'employee_id as code', 'name', DB::raw("CASE hq_region WHEN 'northern' THEN 'PSU' WHEN 'southern' THEN 'PSS' ELSE 'HQ' END office")]);
        return ['scope' => $forced, 'staff' => $forced ? $list->where('office', $forced)->values() : $list];
    }

    /* ── alerts, geofences, manpower vs production ────────────────────── */

    /** Clock-ins with verification flags (outside fence, same spot, low accuracy, no GPS, no selfie). */
    public function alerts(Request $r)
    {
        $office = $this->officeFor($r);
        [$from, $to] = $this->range($r, 30);
        $rows = array_values(array_filter($this->rows($from, $to, null, $office), fn ($x) => $x['flags'] && ($r->boolean('all') || !$x['reviewed_at'])));
        usort($rows, fn ($a, $b) => strcmp($b['date'] . $b['tin'], $a['date'] . $a['tin']));
        $count = fn ($f) => count(array_filter($rows, fn ($x) => in_array($f, $x['flags'], true)));
        return ['from' => $from, 'to' => $to, 'alerts' => $rows, 'labels' => \App\Services\ClockVerifier::FLAG_LABEL,
            'counts' => collect(array_keys(\App\Services\ClockVerifier::FLAG_LABEL))->mapWithKeys(fn ($f) => [$f => $count($f)])];
    }

    public function review(Request $r, $id)
    {
        $this->hrOnly($r);
        $d = $r->validate(['note' => 'nullable|string|max:255']);
        DB::table('attendances')->where('id', $id)->update(['reviewed_at' => now(), 'reviewed_by' => $r->user()->id, 'review_note' => $d['note'] ?? null]);
        return ['ok' => true];
    }

    public function geofences(Request $r)
    {
        $this->hrOnly($r);
        $use = DB::table('attendances')->whereNotNull('site_id')->selectRaw('site_id, count(*) c')->groupBy('site_id')->pluck('c', 'site_id');
        $sites = DB::table('sites')->whereNotNull('geom')->orderBy('site_code')
            ->get(['id', 'site_code', 'name', 'geofence_m', DB::raw('ST_Y(geom) lat'), DB::raw('ST_X(geom) lng')])
            ->map(fn ($s) => (array) $s + ['records' => (int) ($use[$s->id] ?? 0)]);
        return ['sites' => $sites, 'offices' => DB::table('work_locations')->orderBy('id')->get(), 'can_edit' => $r->user()->hasAnyRole(['ess_hr', 'super_admin'])];
    }

    public function updateGeofence(Request $r, string $kind, $id)
    {
        abort_unless($r->user()->hasAnyRole(['ess_hr', 'super_admin']), 403, 'Hanya HR boleh mengubah geofence.');
        if ($kind === 'site') {
            $d = $r->validate(['geofence_m' => 'required|integer|min:0|max:20000']);
            DB::table('sites')->where('id', $id)->update($d);
        } else {
            $d = $r->validate(['lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'radius_m' => 'integer|min:20|max:20000', 'active' => 'boolean']);
            DB::table('work_locations')->where('id', $id)->update($d + ['updated_at' => now()]);
        }
        return ['ok' => true];
    }

    /**
     * Manpower vs production per site: staff present at the site each day (attendance linked to the site, or an ESS
     * clock-in inside that site's fence) against MT sold that day from the site's F001 daily sales.
     */
    public function manpower(Request $r)
    {
        $this->hrOnly($r);
        [$from, $to] = $this->range($r, (int) $r->input('days', 90));
        $staff = DB::select("
            SELECT s.id site_id, s.site_code, a.date::text date, count(DISTINCT a.employee_id) staff
            FROM attendances a JOIN sites s ON s.id = a.site_id OR (a.site_id IS NULL AND a.in_place = s.site_code AND a.in_fence)
            WHERE a.date BETWEEN ? AND ? AND a.timeclock_in IS NOT NULL GROUP BY 1,2,3", [$from, $to]);
        $sales = DB::select("
            SELECT wr.site_id, dr.date::text date, round(sum(dsr.jualan)::numeric,1) mt, sum(dsr.lori_trip) trips
            FROM daily_sales_records dsr JOIN daily_reports dr ON dr.id = dsr.daily_report_id JOIN weekly_reports wr ON wr.id = dr.weekly_report_id
            WHERE dr.date BETWEEN ? AND ? AND NOT (" . InsightsController::ANOMALY . ") GROUP BY 1,2", [$from, $to]);
        $mt = []; foreach ($sales as $x) $mt[$x->site_id][$x->date] = [(float) $x->mt, (int) $x->trips];
        // contractor workforce reported in the F001 daily report (operators, drivers, mechanics, general workers)
        $crewRows = DB::select("
            SELECT wr.site_id, dr.date::text date, sum(COALESCE(mekanik_count,0)+COALESCE(operator_longarm_count,0)+COALESCE(operator_palong_count,0)
              +COALESCE(pekerja_am_count,0)+COALESCE(pemandu_excavator_count,0)+COALESCE(pemandu_lori_count,0)+COALESCE(pemandu_motor_grader_count,0)
              +COALESCE(pemandu_rb_count,0)+COALESCE(pemandu_shovel_count,0)+COALESCE(pemandu_tractor_count,0)+COALESCE(pemandu_water_tanker_count,0)) crew
            FROM daily_reports dr JOIN weekly_reports wr ON wr.id = dr.weekly_report_id WHERE dr.date BETWEEN ? AND ? GROUP BY 1,2", [$from, $to]);
        $crew = []; foreach ($crewRows as $x) $crew[$x->site_id][$x->date] = (int) $x->crew;
        $pearson = function ($xs, $ys) {
            $n = count($xs); if ($n < 5) return null; $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n; $c = $vx = $vy = 0;
            for ($i = 0; $i < $n; $i++) { $c += ($xs[$i] - $mx) * ($ys[$i] - $my); $vx += ($xs[$i] - $mx) ** 2; $vy += ($ys[$i] - $my) ** 2; }
            return $vx && $vy ? round($c / sqrt($vx * $vy), 2) : null;
        };
        $staffBy = []; $codes = [];
        foreach ($staff as $x) { $staffBy[$x->site_id][$x->date] = (int) $x->staff; $codes[$x->site_id] = $x->site_code; }
        foreach (DB::table('sites')->whereIn('id', array_keys($crew))->pluck('site_code', 'id') as $id => $c) $codes[$id] ??= $c;
        $out = [];
        foreach ($codes as $siteId => $code) {
            $dates = array_unique(array_merge(array_keys($staffBy[$siteId] ?? []), array_keys($crew[$siteId] ?? [])));
            sort($dates);
            $pairs = collect($dates)->map(fn ($d) => ['date' => $d, 'staff' => $staffBy[$siteId][$d] ?? 0, 'crew' => $crew[$siteId][$d] ?? 0,
                'mt' => $mt[$siteId][$d][0] ?? 0.0, 'trips' => $mt[$siteId][$d][1] ?? 0]);
            if ($pairs->count() < 5 || !$pairs->sum('mt')) continue;
            $worked = $pairs->filter(fn ($p) => $p['crew'] > 0);
            $out[] = ['site_id' => $siteId, 'site_code' => $code, 'days' => $pairs->count(),
                'avg_staff' => round($pairs->avg('staff'), 1), 'avg_crew' => round($worked->avg('crew') ?? 0, 1), 'avg_mt' => round($pairs->avg('mt')),
                'mt_per_crew_day' => $worked->sum('crew') ? round($worked->sum('mt') / $worked->sum('crew')) : null,
                'mt_per_staff_day' => $pairs->sum('staff') ? round($pairs->sum('mt') / $pairs->sum('staff')) : null,
                'r_crew' => $pearson($pairs->pluck('crew')->all(), $pairs->pluck('mt')->all()),
                'r' => $pearson($pairs->pluck('staff')->all(), $pairs->pluck('mt')->all()),
                'idle_days' => $pairs->filter(fn ($p) => $p['mt'] == 0 && ($p['crew'] > 0 || $p['staff'] > 0))->count(),   // people on site, nothing sold
                'unsupervised_days' => $pairs->filter(fn ($p) => $p['mt'] > 0 && $p['staff'] == 0)->count(),               // sales with no KSSB staff present
                'series' => $pairs->values()];
        }
        usort($out, fn ($a, $b) => $b['avg_mt'] <=> $a['avg_mt']);
        return ['from' => $from, 'to' => $to, 'sites' => $out];
    }
}

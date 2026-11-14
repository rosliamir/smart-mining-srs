<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Decision Intelligence for the dashboard.
 *
 * Answers three questions for a period (a month, compared against the same
 * day-range of the previous month so a partial month is compared fairly):
 *   1. WHAT happened   – KPIs + deltas + monthly trend
 *   2. WHY it happened – sales variance broken down by site, each with the
 *                        operational drivers that moved (operating hours, rain,
 *                        manpower, system downtime, missing reports)
 *   3. WHAT TO DO      – a prioritised, rule-based action list with owner + impact
 *
 * Weather slot codes in F001 daily reports: "X" = operating slot (30 min),
 * "/" = rain slot. "X" correlates strongly with sales (avg 2,149 MT/day with
 * ≥12 X-slots vs 25 MT/day with none).
 */
class InsightsController extends Controller
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // a sales row is implausible when one lorry trip carries > 100 MT (p99 is ~47 MT) or a day exceeds 20,000 MT
    public const ANOMALY = "COALESCE(jualan,0) > 20000 OR (COALESCE(lori_trip,0) > 0 AND jualan / lori_trip > 100)";

    public function index(Request $req)
    {
        $region = $req->input('region');               // region name or null
        $maxDate = Carbon::parse(DB::table('daily_reports')->max('date') ?? now());
        $today = now()->startOfDay();
        $year = (int) $req->input('year', $maxDate->year);
        $month = (int) $req->input('month', $year === $maxDate->year ? $maxDate->month : 12);

        $from = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $from->copy()->endOfMonth()->startOfDay();
        $lastData = Carbon::parse(DB::table('daily_reports')->whereBetween('date', [$from, $monthEnd])->max('date') ?? $from);
        $partial = $lastData->lt($monthEnd) && $maxDate->lt($monthEnd);   // only the month still in progress is partial
        $to = $partial ? $lastData : $monthEnd;
        $daysElapsed = $from->diffInDays($to) + 1;
        $pFrom = $from->copy()->subMonthNoOverflow();
        $pTo = $partial ? $pFrom->copy()->addDays(min($daysElapsed, $pFrom->daysInMonth) - 1) : $pFrom->copy()->endOfMonth()->startOfDay();

        $cur = $this->siteMetrics($from, $to, $region);
        $prev = $this->siteMetrics($pFrom, $pTo, $region);
        $sites = $this->sites($region, $today);

        $kCur = $this->totals($cur);
        $kPrev = $this->totals($prev);
        $drivers = $this->drivers($cur, $prev, $sites);
        $trend = $this->monthlyTrend($year, $region);
        $daily = $this->daily($from, $to, $region, $req->input('site'));
        $backlog = $this->backlog($today, $region);
        $health = $this->health($sites, $cur, $prev, $drivers);
        $actions = $this->actions($sites, $cur, $prev, $drivers, $backlog, $kCur, $kPrev);
        $anomalies = $this->anomalies($region);
        if ($anomalies) {
            $mt = array_sum(array_column($anomalies, 'sales'));
            array_unshift($actions, ['id' => 'anomaly', 'sev' => 'critical', 'cat' => 'Data quality',
                'title' => count($anomalies) . ' implausible sales entries (' . number_format($mt) . ' MT) — excluded from analytics',
                'why' => 'More than 100 MT per lorry trip, e.g. ' . implode(', ', array_map(fn ($a) => "{$a['code']} {$a['date']}: " . number_format($a['sales']) . " MT / {$a['trips']} trips", array_slice($anomalies, 0, 3))),
                'action' => 'Correct the F001 daily sales entries (likely a decimal / unit typo) and re-sync', 'owner' => 'Executive',
                'impact' => number_format($mt) . ' MT would distort totals', 'link' => null, 'site' => null]);
        }

        // month-end projection from the current run-rate
        $forecast = null;
        if ($partial) {
            $days = $from->daysInMonth;
            $prevFull = array_sum(array_column($this->siteMetrics($pFrom, $pFrom->copy()->endOfMonth(), $region), 'sales'));
            $forecast = [
                'mtd' => $kCur['sales'], 'days_elapsed' => $daysElapsed, 'days_in_month' => $days,
                'projected' => round($kCur['sales'] / max(1, $daysElapsed) * $days, 2),
                'prev_month_total' => round($prevFull, 2),
            ];
        }

        return [
            'period' => [
                'year' => $year, 'month' => $month, 'label' => self::MONTHS[$month] . " $year",
                'from' => $from->toDateString(), 'to' => $to->toDateString(), 'partial' => $partial,
                'prev_from' => $pFrom->toDateString(), 'prev_to' => $pTo->toDateString(),
                'prev_label' => self::MONTHS[$pFrom->month] . ' ' . $pFrom->year . ($partial ? " (1–{$pTo->day})" : ''),
                'available' => $this->availableMonths(),
                'region' => $region,
            ],
            'kpis' => $this->kpis($kCur, $kPrev, $trend, $sites),
            'summary' => $this->summary($kCur, $kPrev, $drivers, $actions, $forecast, $partial),
            'drivers' => $drivers,
            'trend' => $trend,
            'daily' => $daily,
            'forecast' => $forecast,
            'actions' => $actions,
            'health' => $health,
            'backlog' => $backlog,
            'anomalies' => $anomalies,
        ];
    }

    /* ── data ─────────────────────────────────────────────────────────── */

    /** Per-day operational facts joined from all F001 child tables. */
    private function dailyFacts(): string
    {
        return "
          SELECT dr.id, dr.date, wr.site_id, s.region_id,
            (SELECT count(*) FROM jsonb_each_text(COALESCE(dr.weather_slots,'{}')) e WHERE e.value='X')/2.0 AS op_h,
            (SELECT count(*) FROM jsonb_each_text(COALESCE(dr.weather_slots,'{}')) e WHERE e.value='/') AS rain,
            COALESCE(sr.sales,0) sales, COALESCE(sr.trips,0) trips,
            COALESCE(a.present,0) present, COALESCE(a.absent,0) absent, COALESCE(a.on_leave,0) on_leave,
            (ss.e_docket_status='rosak_off')::int edocket_down,
            (ss.cctv_luar_status='rosak_off' OR ss.cctv_dalam_kabin_status='rosak_off')::int cctv_down,
            (ss.cty_gemma_status='rosak_off')::int gemma_down,
            (es.effluent_pagi='keruh' AND es.effluent_tghari='keruh' AND es.effluent_petang='keruh')::int effluent_bad,
            (es.spillway='x_berfungsi')::int spillway_bad
          FROM daily_reports dr
          JOIN weekly_reports wr ON wr.id=dr.weekly_report_id
          JOIN sites s ON s.id=wr.site_id
          LEFT JOIN (SELECT daily_report_id, sum(jualan) sales, sum(lori_trip) trips FROM daily_sales_records WHERE NOT (" . self::ANOMALY . ") GROUP BY 1) sr ON sr.daily_report_id=dr.id
          LEFT JOIN (SELECT daily_report_id, count(*) FILTER (WHERE status='present') present,
                       count(*) FILTER (WHERE status='absent') absent, count(*) FILTER (WHERE status='on_leave') on_leave
                     FROM report_attendances GROUP BY 1) a ON a.daily_report_id=dr.id
          LEFT JOIN daily_system_statuses ss ON ss.daily_report_id=dr.id
          LEFT JOIN daily_environment_statuses es ON es.daily_report_id=dr.id";
    }

    private function regionClause(?string $region, array &$bind): string
    {
        if (!$region) return '';
        $bind[] = $region;
        return ' AND s.region_id = (SELECT id FROM regions WHERE name=? LIMIT 1)';
    }

    private function siteMetrics(Carbon $from, Carbon $to, ?string $region): array
    {
        $bind = [$from->toDateString(), $to->toDateString()];
        $sql = "WITH f AS ({$this->dailyFacts()} WHERE dr.date BETWEEN ? AND ?" . $this->regionClause($region, $bind) . ")
          SELECT site_id, count(DISTINCT date) report_days, count(*) rows_n, round(sum(sales)::numeric,2) sales, sum(trips) trips,
            round(sum(op_h)::numeric,1) op_h, sum(rain) rain, sum(present) present, sum(absent) absent, sum(on_leave) on_leave,
            sum(edocket_down) edocket_down, sum(cctv_down) cctv_down, sum(gemma_down) gemma_down,
            sum(effluent_bad) effluent_bad, sum(spillway_bad) spillway_bad
          FROM f GROUP BY site_id";
        $out = [];
        foreach (DB::select($sql, $bind) as $r) {
            $out[$r->site_id] = array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, (array) $r);
        }
        return $out;
    }

    private function sites(?string $region, Carbon $today): array
    {
        $q = DB::table('sites as s')->leftJoin('regions as r', 'r.id', '=', 's.region_id')
            ->leftJoin('contractors as c', 'c.id', '=', 's.contractor_id')
            ->select('s.id', 's.site_code', 's.name', 's.permit_date_end', 's.is_activated', 'r.name as region', 'c.name as contractor');
        if ($region) $q->where('r.name', $region);
        $out = [];
        foreach ($q->get() as $s) {
            $days = $s->permit_date_end ? (int) $today->diffInDays(Carbon::parse($s->permit_date_end), false) : null;
            $out[$s->id] = [
                'id' => $s->id, 'code' => $s->site_code, 'name' => $s->name, 'region' => $s->region, 'contractor' => $s->contractor,
                'days_left' => $days, 'permit_end' => $s->permit_date_end,
                'permit' => $days === null ? 'unknown' : ($days < 0 ? 'expired' : ($days <= 90 ? 'warning' : 'active')),
            ];
        }
        return $out;
    }

    private function totals(array $m): array
    {
        $sum = fn ($k) => array_sum(array_column($m, $k));
        $days = $sum('report_days'); $rows = $sum('rows_n');
        $staff = $sum('present') + $sum('absent') + $sum('on_leave');
        return [
            'sales' => round($sum('sales'), 2), 'trips' => (int) $sum('trips'), 'op_h' => round($sum('op_h'), 1),
            'rain' => (int) $sum('rain'), 'report_days' => (int) $days, 'sites' => count($m),
            'attendance' => $staff ? round($sum('present') / $staff * 100, 1) : null,
            'uptime' => $rows ? round((1 - ($sum('edocket_down') + $sum('cctv_down')) / (2 * $rows)) * 100, 1) : null,
            'env' => $rows ? round((1 - ($sum('effluent_bad') + $sum('spillway_bad')) / (2 * $rows)) * 100, 1) : null,
            'per_trip' => $sum('trips') ? round($sum('sales') / $sum('trips'), 1) : null,
            'sales_per_op_h' => $sum('op_h') ? round($sum('sales') / $sum('op_h'), 1) : null,
        ];
    }

    private function monthlyTrend(int $year, ?string $region): array
    {
        $bind = [$year];
        $sql = "WITH f AS ({$this->dailyFacts()} WHERE extract(year from dr.date)=?" . $this->regionClause($region, $bind) . ")
          SELECT extract(month from date)::int m, round(sum(sales)::numeric,2) sales, sum(trips) trips, round(sum(op_h)::numeric,1) op_h,
            sum(rain) rain, count(*) report_days, count(DISTINCT site_id) sites,
            sum(present) present, sum(absent)+sum(present)+sum(on_leave) staff,
            sum(edocket_down)+sum(cctv_down) down, sum(effluent_bad)+sum(spillway_bad) env_bad
          FROM f GROUP BY 1 ORDER BY 1";
        $rows = collect(DB::select($sql, $bind))->keyBy('m');
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $r = $rows[$m] ?? null;
            $out[] = [
                'month' => $m, 'label' => self::MONTHS[$m],
                'sales' => $r ? (float) $r->sales : 0, 'trips' => $r ? (int) $r->trips : 0, 'op_h' => $r ? (float) $r->op_h : 0,
                'rain' => $r ? (int) $r->rain : 0, 'sites' => $r ? (int) $r->sites : 0, 'report_days' => $r ? (int) $r->report_days : 0,
                'attendance' => $r && $r->staff ? round($r->present / $r->staff * 100, 1) : null,
                'uptime' => $r && $r->report_days ? round((1 - $r->down / (2 * $r->report_days)) * 100, 1) : null,
                'env' => $r && $r->report_days ? round((1 - $r->env_bad / (2 * $r->report_days)) * 100, 1) : null,
            ];
        }
        return $out;
    }

    private function daily(Carbon $from, Carbon $to, ?string $region, $site): array
    {
        $bind = [$from->toDateString(), $to->toDateString()];
        $where = $this->regionClause($region, $bind);
        if ($site) { $where .= ' AND wr.site_id=?'; $bind[] = (int) $site; }
        $sql = "WITH f AS ({$this->dailyFacts()} WHERE dr.date BETWEEN ? AND ?$where)
          SELECT date::text, round(sum(sales)::numeric,2) sales, sum(trips) trips, round(avg(op_h)::numeric,1) op_h,
            round(avg(rain)::numeric,1) rain, sum(absent) absent, sum(present) present,
            sum(edocket_down) edocket_down, sum(cctv_down) cctv_down, count(*) reports
          FROM f GROUP BY date ORDER BY date";
        return array_map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, (array) $r), DB::select($sql, $bind));
    }

    private function backlog(Carbon $today, ?string $region): array
    {
        $q = DB::table('weekly_reports as wr')->join('sites as s', 's.id', '=', 'wr.site_id')
            ->leftJoin('regions as r', 'r.id', '=', 's.region_id')
            ->whereIn('wr.status', ['draft', 'pending_review', 'pending_approval', 'returned']);
        if ($region) $q->where('r.name', $region);
        $rows = $q->selectRaw('wr.status, count(*) c, min(wr.end_effective_date) oldest,
            count(*) FILTER (WHERE wr.end_effective_date < ?) overdue', [$today->copy()->subDays(7)->toDateString()])
            ->groupBy('wr.status')->get()->keyBy('status');
        $get = fn ($s) => ['count' => (int) ($rows[$s]->c ?? 0), 'overdue' => (int) ($rows[$s]->overdue ?? 0), 'oldest' => $rows[$s]->oldest ?? null];
        return ['draft' => $get('draft'), 'pending_review' => $get('pending_review'), 'pending_approval' => $get('pending_approval'), 'returned' => $get('returned')];
    }

    /** Implausible sales rows — excluded from every KPI/chart above and listed for correction. */
    private function anomalies(?string $region): array
    {
        $bind = [];
        $sql = "SELECT dsr.id, s.id site_id, s.site_code code, dr.date::text date, dsr.jualan sales, dsr.lori_trip trips,
                  round(dsr.jualan / NULLIF(dsr.lori_trip,0), 1) per_trip, wr.id weekly_id
                FROM daily_sales_records dsr JOIN daily_reports dr ON dr.id=dsr.daily_report_id
                JOIN weekly_reports wr ON wr.id=dr.weekly_report_id JOIN sites s ON s.id=wr.site_id
                WHERE (" . self::ANOMALY . ")" . $this->regionClause($region, $bind) . " ORDER BY dsr.jualan DESC";
        return array_map(fn ($r) => (array) $r + ['likely' => $r->trips ? round($r->trips * 25) : null], DB::select($sql, $bind));
    }

    private function availableMonths(): array
    {
        return collect(DB::select("SELECT DISTINCT extract(year from date)::int y, extract(month from date)::int m FROM daily_reports ORDER BY 1,2"))
            ->map(fn ($r) => ['year' => $r->y, 'month' => $r->m, 'label' => self::MONTHS[$r->m] . " {$r->y}"])->values()->all();
    }

    /* ── WHY ──────────────────────────────────────────────────────────── */

    private function pct($a, $b): ?float { return $b ? round(($a - $b) / $b * 100, 1) : null; }

    /** Each site's contribution to the sales change, with the operational factors that explain it. */
    private function drivers(array $cur, array $prev, array $sites): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($cur), array_keys($prev))) as $id) {
            if (!isset($sites[$id])) continue;
            $c = $cur[$id] ?? null; $p = $prev[$id] ?? null;
            $cs = $c['sales'] ?? 0; $ps = $p['sales'] ?? 0;
            $delta = round($cs - $ps, 2);
            if (abs($delta) < 1) continue;
            $f = [];   // factors, most important first
            $cd = $c['report_days'] ?? 0; $pd = $p['report_days'] ?? 0;
            if ($pd && $cd < $pd * 0.75) $f[] = ['key' => 'reports', 'dir' => 'down', 'text' => sprintf('Fewer daily reports submitted (%d vs %d days)', $cd, $pd)];
            $cn = $c['rows_n'] ?? 0; $pn = $p['rows_n'] ?? 0;
            $ch = $cn ? ($c['op_h'] / $cn) : 0; $ph = $pn ? ($p['op_h'] / $pn) : 0;
            if ($ph && ($d = $this->pct($ch, $ph)) !== null && abs($d) >= 15)
                $f[] = ['key' => 'op_h', 'dir' => $d < 0 ? 'down' : 'up', 'text' => sprintf('Operating hours/day %s %s%% (%.1fh → %.1fh)', $d < 0 ? 'down' : 'up', abs($d), $ph, $ch)];
            // period-vs-period comparisons only make sense when the site reported in both periods
            if ($cn && $pn) {
                $cr = $c['rain'] / $cn; $pr = $p['rain'] / $pn;
                if ($cr - $pr >= 1) $f[] = ['key' => 'rain', 'dir' => 'up', 'text' => sprintf('More rain: %.1f rain slots/day vs %.1f', $cr, $pr)];
                elseif ($pr - $cr >= 1) $f[] = ['key' => 'rain', 'dir' => 'down', 'text' => sprintf('Less rain: %.1f rain slots/day vs %.1f', $cr, $pr)];
                $ca = $c['present'] + $c['absent']; $pa = $p['present'] + $p['absent'];
                $car = $ca ? $c['absent'] / $ca * 100 : 0; $par = $pa ? $p['absent'] / $pa * 100 : 0;
                if ($car - $par >= 8) $f[] = ['key' => 'manpower', 'dir' => 'down', 'text' => sprintf('Absenteeism up: %.0f%% vs %.0f%%', $car, $par)];
            }
            if (($c['edocket_down'] ?? 0) >= 2) $f[] = ['key' => 'edocket', 'dir' => 'down', 'text' => sprintf('e-Docket down %d day(s) — sales may be unrecorded', $c['edocket_down'])];
            if (($c['cctv_down'] ?? 0) >= 2) $f[] = ['key' => 'cctv', 'dir' => 'down', 'text' => sprintf('CCTV down %d day(s)', $c['cctv_down'])];
            $ct = $c['trips'] ?? 0; $pt = $p['trips'] ?? 0;
            if ($pt && ($d = $this->pct($ct, $pt)) !== null && abs($d) >= 20) $f[] = ['key' => 'trips', 'dir' => $d < 0 ? 'down' : 'up', 'text' => sprintf('Lorry trips %s %s%% (%d → %d)', $d < 0 ? 'down' : 'up', abs($d), $pt, $ct)];
            if (!$c) $f = [['key' => 'reports', 'dir' => 'down', 'text' => 'No daily reports in this period']];
            if (!$p) $f[] = ['key' => 'new', 'dir' => 'up', 'text' => 'No activity in the comparison period (restart / new site)'];
            if (!$f) $f[] = ['key' => 'demand', 'dir' => $delta < 0 ? 'down' : 'up', 'text' => 'Operations look normal — change is likely demand / pricing driven'];
            $s = $sites[$id];
            $out[] = ['site_id' => $id, 'code' => $s['code'], 'name' => $s['name'], 'region' => $s['region'],
                'sales' => $cs, 'prev_sales' => $ps, 'delta' => $delta, 'delta_pct' => $this->pct($cs, $ps), 'factors' => $f];
        }
        usort($out, fn ($a, $b) => abs($b['delta']) <=> abs($a['delta']));
        return $out;
    }

    /* ── WHAT TO DO ───────────────────────────────────────────────────── */

    private function actionFor(string $key): array
    {
        return [
            'reports'  => ['Follow up with the site officer to submit missing F001 daily reports', 'Executive'],
            'op_h'     => ['Find out why operating hours dropped (breakdown? stoppage?) and plan a catch-up shift', 'Site officer'],
            'rain'     => ['Weather-related: schedule catch-up on dry days and protect stockpile from washout', 'Site officer'],
            'manpower' => ['Arrange relief operators / review leave approvals for this site', 'Manager'],
            'edocket'  => ['Send a technician to restore e-Docket — sales may be going unrecorded', 'IT / Technician'],
            'cctv'     => ['Repair CCTV — site is unmonitored', 'IT / Technician'],
            'trips'    => ['Check lorry availability / buyer demand for this site', 'Executive'],
            'demand'   => ['Review pricing & buyer pipeline with the contractor', 'Manager'],
            'new'      => ['Confirm site restart is authorised and permit valid', 'Executive'],
        ][$key] ?? ['Investigate with the site officer', 'Executive'];
    }

    private function actions(array $sites, array $cur, array $prev, array $drivers, array $backlog, array $kCur, array $kPrev): array
    {
        $A = [];
        $add = function ($sev, $cat, $title, $why, $action, $owner, $impact = null, $link = null, $site = null) use (&$A) {
            $A[] = compact('sev', 'cat', 'title', 'why', 'action', 'owner', 'impact', 'link', 'site') + ['id' => md5($cat . $title)];
        };

        // 1. operating on an expired permit
        $opExpired = array_filter($sites, fn ($s) => $s['permit'] === 'expired' && isset($cur[$s['id']]));
        if ($opExpired) {
            $mt = array_sum(array_map(fn ($s) => $cur[$s['id']]['sales'], $opExpired));
            $add('critical', 'Permit', count($opExpired) . ' site(s) still operating on an EXPIRED permit',
                'Daily reports/sales were recorded this period at: ' . implode(', ', array_slice(array_column($opExpired, 'code'), 0, 6)) . (count($opExpired) > 6 ? '…' : ''),
                'Suspend operations or obtain permit renewal immediately; notify the land office', 'Manager',
                number_format($mt) . ' MT sold under expired permit', '/map');
        }
        // 2. permits expiring soon
        $soon = array_filter($sites, fn ($s) => $s['days_left'] !== null && $s['days_left'] >= 0 && $s['days_left'] <= 30);
        if ($soon) {
            usort($soon, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);
            $add('high', 'Permit', count($soon) . ' permit(s) expire within 30 days',
                implode(', ', array_map(fn ($s) => "{$s['code']} ({$s['days_left']}d)", array_slice($soon, 0, 6))),
                'Submit renewal applications now to avoid stoppage', 'Executive', null, '/map');
        }
        $mid = array_filter($sites, fn ($s) => $s['days_left'] !== null && $s['days_left'] > 30 && $s['days_left'] <= 90);
        if ($mid) $add('medium', 'Permit', count($mid) . ' permit(s) expire in 31–90 days', 'Plan renewal paperwork this month',
            'Prepare renewal documents (LUAS approval, site report)', 'Executive', null, '/map');

        // 3. biggest sales drops with their root cause
        foreach (array_slice(array_filter($drivers, fn ($d) => $d['delta'] < 0 && $d['prev_sales'] > 2000 && ($d['delta_pct'] ?? -100) <= -25), 0, 5) as $d) {
            [$act, $owner] = $this->actionFor($d['factors'][0]['key']);
            $add(($d['delta_pct'] ?? -100) <= -50 ? 'high' : 'medium', 'Sales', "{$d['code']}: sales down " . abs((int) ($d['delta_pct'] ?? 100)) . '%',
                implode(' · ', array_column(array_slice($d['factors'], 0, 3), 'text')), $act, $owner,
                number_format(abs($d['delta'])) . ' MT below last period', "/site/{$d['site_id']}", $d['site_id']);
        }

        // 4. system downtime (revenue leakage risk)
        foreach ($cur as $id => $m) {
            if (!isset($sites[$id])) continue;
            $down = $m['edocket_down'] + $m['cctv_down'];
            if ($m['edocket_down'] >= 3 || $down >= 5)
                $add('high', 'Systems', "{$sites[$id]['code']}: e-Docket/CCTV down {$down} day(s)",
                    "e-Docket down {$m['edocket_down']}d, CCTV down {$m['cctv_down']}d this period — sales and lorry movements may be unverified",
                    'Dispatch technician; cross-check weighbridge AVR vs docket totals for those days', 'IT / Technician', null, "/site/$id", $id);
        }

        // 5. environment compliance
        $spill = []; $turbid = [];
        foreach ($cur as $id => $m) {
            if (!isset($sites[$id]) || !$m['rows_n']) continue;
            if ($m['spillway_bad'] >= 3) $spill[] = "{$sites[$id]['code']} ({$m['spillway_bad']}d)";
            elseif ($m['rows_n'] >= 5 && $m['effluent_bad'] / $m['rows_n'] >= 0.7) $turbid[] = $sites[$id]['code'] . ' (' . round($m['effluent_bad'] / $m['rows_n'] * 100) . '%)';
        }
        if ($spill) $add(count($spill) >= 5 ? 'high' : 'medium', 'Environment', count($spill) . ' site(s) with spillway not working ≥ 3 days',
            implode(', ', array_slice($spill, 0, 8)) . (count($spill) > 8 ? '…' : ''),
            'Repair spillways — silt overflow risks river pollution and JAS penalties', 'Site officer');
        if ($turbid) $add('medium', 'Environment', count($turbid) . ' site(s) with turbid effluent most days',
            'Effluent turbid morning, noon & evening: ' . implode(', ', array_slice($turbid, 0, 8)) . (count($turbid) > 8 ? '…' : ''),
            'Desilt settling ponds / add retention time before discharge', 'Site officer');

        // 6. data gaps: reported last period, silent now
        $silentZero = [];
        foreach ($prev as $id => $m) {
            if (!isset($sites[$id]) || isset($cur[$id]) || $m['report_days'] < 3) continue;
            if ($m['sales'] > 0)
                $add('high', 'Data', "{$sites[$id]['code']}: no daily reports this period",
                    "Site reported {$m['report_days']} days last period (" . number_format($m['sales']) . ' MT) but nothing this period',
                    'Confirm whether the site stopped operating or the officer stopped reporting', 'Executive',
                    number_format($m['sales']) . ' MT last period', "/site/$id", $id);
            else $silentZero[] = $sites[$id]['code'];
        }
        if ($silentZero) $add('low', 'Data', count($silentZero) . ' non-selling site(s) stopped reporting',
            implode(', ', array_slice($silentZero, 0, 10)), 'Confirm these sites are closed or remind officers to report', 'Executive');

        // 7. approval backlog
        if ($backlog['pending_review']['overdue'])
            $add('medium', 'Workflow', "{$backlog['pending_review']['overdue']} F001 report(s) waiting review > 7 days",
                'Oldest week ended ' . $backlog['pending_review']['oldest'], 'Executives to validate pending F001 reports', 'Executive', null, '/reports');
        if ($backlog['pending_approval']['overdue'])
            $add('medium', 'Workflow', "{$backlog['pending_approval']['overdue']} F001 report(s) waiting final approval > 7 days",
                'Oldest week ended ' . $backlog['pending_approval']['oldest'], 'Manager to final-approve validated F001 reports', 'Manager', null, '/reports');

        // 8. company-wide manpower
        if ($kCur['attendance'] !== null && $kPrev['attendance'] !== null && $kCur['attendance'] < $kPrev['attendance'] - 5)
            $add('medium', 'Manpower', "Attendance fell to {$kCur['attendance']}%", "Was {$kPrev['attendance']}% in the comparison period",
                'Review absenteeism by site and leave approvals', 'Manager', null, '/attendance');

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($A, fn ($a, $b) => $rank[$a['sev']] <=> $rank[$b['sev']]);
        return $A;
    }

    /* ── scoring + narrative ──────────────────────────────────────────── */

    private function health(array $sites, array $cur, array $prev, array $drivers): array
    {
        $byId = collect($drivers)->keyBy('site_id');
        $out = [];
        foreach ($sites as $id => $s) {
            $c = $cur[$id] ?? null; $p = $prev[$id] ?? null;
            if (!$c && !$p) continue;    // dormant site
            $score = 100; $why = [];
            if ($s['permit'] === 'expired') { $score -= 30; $why[] = 'Permit expired'; }
            elseif ($s['permit'] === 'warning') { $score -= 10; $why[] = "Permit {$s['days_left']}d left"; }
            elseif ($s['permit'] === 'unknown') { $score -= 5; $why[] = 'No permit date'; }
            $dp = $byId[$id]['delta_pct'] ?? null;
            if (!$c) { $score -= 35; $why[] = 'No reports'; }
            elseif ($dp !== null && $dp <= -30) { $score -= 20; $why[] = "Sales {$dp}%"; }
            elseif ($dp !== null && $dp <= -10) { $score -= 10; $why[] = "Sales {$dp}%"; }
            if ($c) {
                $down = $c['edocket_down'] + $c['cctv_down'];
                if ($down) { $score -= min(15, $down * 3); $why[] = "Systems down {$down}d"; }
                if ($c['rows_n'] && $c['effluent_bad'] / $c['rows_n'] >= 0.7) { $score -= 10; $why[] = 'Effluent turbid'; }
                if ($c['spillway_bad']) { $score -= min(10, $c['spillway_bad'] * 2); $why[] = 'Spillway issue'; }
                $staff = $c['present'] + $c['absent'];
                if ($staff && $c['absent'] / $staff > 0.15) { $score -= 10; $why[] = 'High absenteeism'; }
            }
            $score = max(0, $score);
            $out[] = $s + [
                'score' => $score, 'grade' => $score >= 80 ? 'good' : ($score >= 60 ? 'watch' : 'risk'), 'issues' => $why,
                'sales' => $c['sales'] ?? 0, 'prev_sales' => $p['sales'] ?? 0, 'delta_pct' => $dp,
                'op_h_day' => $c && $c['rows_n'] ? round($c['op_h'] / $c['rows_n'], 1) : null,
                'report_days' => $c['report_days'] ?? 0, 'trips' => $c['trips'] ?? 0,
            ];
        }
        usort($out, fn ($a, $b) => $a['score'] <=> $b['score'] ?: $b['sales'] <=> $a['sales']);
        return $out;
    }

    private function kpis(array $c, array $p, array $trend, array $sites): array
    {
        $spark = fn ($k) => array_values(array_filter(array_map(fn ($t) => $t['report_days'] ? $t[$k] : null, $trend), fn ($v) => $v !== null));
        $expired = count(array_filter($sites, fn ($s) => $s['permit'] === 'expired'));
        $mk = fn ($key, $label, $unit, $val, $prev, $good = 'up', $sp = null, $hint = '') =>
            compact('key', 'label', 'unit', 'hint') + ['value' => $val, 'prev' => $prev, 'delta_pct' => $this->pct($val, $prev),
                'delta_abs' => $val !== null && $prev !== null ? round($val - $prev, 1) : null, 'good' => $good, 'spark' => $sp ?? []];
        return [
            $mk('sales', 'Sales', 'MT', $c['sales'], $p['sales'], 'up', $spark('sales'), 'Total jualan from F001 daily sales records'),
            $mk('trips', 'Lorry trips', 'trips', $c['trips'], $p['trips'], 'up', $spark('trips'), 'Sum of lori_trip'),
            $mk('op_h', 'Operating hours', 'h', $c['op_h'], $p['op_h'], 'up', $spark('op_h'), 'Weather slots marked "X" × 30 min, all sites'),
            $mk('attendance', 'Attendance', '%', $c['attendance'], $p['attendance'], 'up', $spark('attendance'), 'Present / (present + absent + on leave)'),
            $mk('uptime', 'System uptime', '%', $c['uptime'], $p['uptime'], 'up', $spark('uptime'), 'e-Docket & CCTV days not "rosak/off"'),
            $mk('env', 'Env. compliance', '%', $c['env'], $p['env'], 'up', $spark('env'), 'Days without all-day turbid effluent or broken spillway'),
            $mk('rain', 'Rain slots', 'slots', $c['rain'], $p['rain'], 'down', $spark('rain'), 'Weather slots marked "/" (30 min each)'),
            $mk('expired', 'Expired permits', 'sites', $expired, null, 'down', [], 'Sites whose permit end date has passed'),
        ];
    }

    private function summary(array $c, array $p, array $drivers, array $actions, ?array $fc, bool $partial): array
    {
        $what = []; $why = []; $do = [];
        $d = $this->pct($c['sales'], $p['sales']);
        $what[] = sprintf('%s sales %s MT%s', $partial ? 'Month-to-date' : 'Monthly', number_format($c['sales']),
            $d === null ? '' : sprintf(' — %s %s%% vs same period last month', $d < 0 ? 'down' : 'up', abs($d)));
        if ($fc) $what[] = sprintf('At this run-rate the month closes at ~%s MT (last month: %s MT)', number_format($fc['projected']), number_format($fc['prev_month_total']));
        if ($c['op_h'] && $p['op_h']) {
            $h = $this->pct($c['op_h'], $p['op_h']);
            $what[] = sprintf('Operating hours %s %s%%; productivity %s MT per operating hour (was %s)', $h < 0 ? 'down' : 'up', abs($h),
                number_format($c['sales_per_op_h'] ?? 0, 1), number_format($p['sales_per_op_h'] ?? 0, 1));
        }
        $neg = array_filter($drivers, fn ($x) => $x['delta'] < 0);
        $pos = array_filter($drivers, fn ($x) => $x['delta'] > 0);
        $netDown = $c['sales'] < $p['sales'];
        if (!$p['report_days']) {   // first month with data: nothing to compare against
            $why[] = 'No F001 data exists for the comparison period, so there is no change to explain — this is the first month with records.';
            $top = collect($drivers)->sortByDesc('sales')->take(3);
            if ($top->isNotEmpty()) $why[] = 'Largest contributors this month: ' . $top->map(fn ($x) => "{$x['code']} " . number_format($x['sales']) . ' MT')->implode(', ');
            $pos = [];
        }
        foreach (array_slice($netDown ? $neg : $pos, 0, 3) as $x)
            $why[] = sprintf('%s %s%s MT — %s', $x['code'], $x['delta'] < 0 ? '−' : '+', number_format(abs($x['delta'])), lcfirst($x['factors'][0]['text']));
        // company-level factor counts
        $count = [];
        foreach ($neg as $x) foreach ($x['factors'] as $f) $count[$f['key']] = ($count[$f['key']] ?? 0) + 1;
        arsort($count);
        $names = ['op_h' => 'lower operating hours', 'reports' => 'missing daily reports', 'rain' => 'more rain', 'manpower' => 'absenteeism',
            'edocket' => 'e-Docket downtime', 'cctv' => 'CCTV downtime', 'trips' => 'fewer lorry trips', 'demand' => 'demand/pricing'];
        if ($count) {
            $top = array_slice($count, 0, 2, true);
            $why[] = 'Most common cause across declining sites: ' . implode(' and ', array_map(fn ($k, $n) => ($names[$k] ?? $k) . " ($n sites)", array_keys($top), $top));
        }
        $sev = array_count_values(array_column($actions, 'sev'));
        foreach (array_slice($actions, 0, 3) as $a) $do[] = $a['action'] . ' — ' . $a['title'];
        return ['what' => $what, 'why' => $why, 'do' => $do,
            'counts' => ['critical' => $sev['critical'] ?? 0, 'high' => $sev['high'] ?? 0, 'medium' => $sev['medium'] ?? 0, 'low' => $sev['low'] ?? 0],
            'tone' => $d === null ? 'neutral' : ($d <= -10 ? 'bad' : ($d >= 5 ? 'good' : 'neutral'))];
    }
}

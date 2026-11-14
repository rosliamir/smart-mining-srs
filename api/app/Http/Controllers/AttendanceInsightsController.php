<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attendance intelligence: what happened (rates, punctuality, hours), why
 * (weekday / office / role patterns, chronic individuals, data gaps) and
 * what to do (prioritised actions with owner).
 *
 * The legacy "late" status is inconsistent (e.g. "late" at 04:06 while 09:30
 * arrivals are "present"), so punctuality is computed from the clock-in time:
 * arriving after 08:30 (and before 12:00, to exclude afternoon shifts) = late.
 */
class AttendanceInsightsController extends Controller
{
    private const LATE_AFTER = '08:30:00';
    private const OFFICE = ['northern' => 'PSU', 'southern' => 'PSS'];

    public function index(Request $req)
    {
        $end = Carbon::parse($req->input('date', DB::table('attendances')->max('date')));
        $days = (int) $req->input('days', 30);
        $from = $end->copy()->subDays($days - 1);
        $pEnd = $from->copy()->subDay();
        $pFrom = $pEnd->copy()->subDays($days - 1);
        $office = $req->input('office');           // HQ | PSU | PSS | null

        $cur = $this->totals($from, $end, $office);
        $prev = $this->totals($pFrom, $pEnd, $office);
        $trend = $this->trend($end->copy()->subDays(59), $end, $office);
        $weekday = $this->weekday($from, $end, $office);
        $byOffice = $this->groupBy('office', $from, $end, null);
        $byRole = $this->groupBy('role', $from, $end, $office);
        $people = $this->people($from, $end, $office);
        $quality = $this->quality($from, $end, $office);
        $arrivals = $this->arrivals($from, $end, $office);
        $actions = $this->actions($cur, $prev, $weekday, $byOffice, $people, $quality);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $end->toDateString(), 'days' => $days,
                'prev_from' => $pFrom->toDateString(), 'prev_to' => $pEnd->toDateString(), 'office' => $office, 'late_after' => substr(self::LATE_AFTER, 0, 5)],
            'kpis' => $this->kpis($cur, $prev, $trend),
            'summary' => $this->summary($cur, $prev, $weekday, $byOffice, $people, $quality, $actions),
            'trend' => $trend, 'weekday' => $weekday, 'by_office' => $byOffice, 'by_role' => $byRole,
            'arrivals' => $arrivals, 'people' => $people, 'quality' => $quality, 'actions' => $actions,
        ];
    }

    /* ── base query ───────────────────────────────────────────────────── */
    private function base(): string
    {
        return "SELECT a.date, a.status, a.timeclock_in tin, a.timeclock_out tout, e.id emp_id, e.employee_id code, e.name,
              COALESCE(NULLIF(e.job_level,''),'unknown') role,
              CASE e.hq_region WHEN 'northern' THEN 'PSU' WHEN 'southern' THEN 'PSS' ELSE 'HQ' END office,
              (a.status IN ('present','late') OR (a.status IS NULL AND a.timeclock_in IS NOT NULL)) attended,
              (a.timeclock_in > '" . self::LATE_AFTER . "' AND a.timeclock_in < '12:00') late_arr,
              CASE WHEN a.timeclock_in IS NOT NULL AND a.timeclock_out > a.timeclock_in
                   THEN extract(epoch FROM a.timeclock_out - a.timeclock_in)/3600 END hours
            FROM attendances a JOIN employees e ON e.id=a.employee_id";
    }

    private function where(Carbon $from, Carbon $to, ?string $office, array &$b, string $alias = 'x'): string
    {
        $b[] = $from->toDateString(); $b[] = $to->toDateString();
        $w = " WHERE $alias.date BETWEEN ? AND ?";
        if ($office) { $w .= " AND $alias.office = ?"; $b[] = $office; }
        return $w;
    }

    private function agg(): string
    {
        return "count(*) n, count(*) FILTER (WHERE attended) attended, count(*) FILTER (WHERE status='absent') absent,
          count(*) FILTER (WHERE status='on_leave') leave, count(*) FILTER (WHERE status IS NULL AND tin IS NULL) blank, count(*) FILTER (WHERE late_arr) late,
          count(*) FILTER (WHERE tin IS NOT NULL AND tin < '12:00') clocked_am,
          round(avg(hours)::numeric,2) avg_h, round(avg(extract(epoch FROM tin)/3600) FILTER (WHERE tin < '12:00')::numeric,3) avg_in";
    }

    private function totals(Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        $r = DB::selectOne("SELECT {$this->agg()}, count(DISTINCT emp_id) staff FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b), $b);
        return $this->derive((array) $r);
    }

    private function derive(array $r): array
    {
        $exp = max(1, $r['n'] - $r['leave'] - ($r['blank'] ?? 0));   // expected at work (blank rows = rest day for 5-day staff)
        $r['rate'] = round($r['attended'] / $exp * 100, 1);
        $r['absent_rate'] = round($r['absent'] / $exp * 100, 1);
        $r['punctual'] = $r['clocked_am'] ? round((1 - $r['late'] / $r['clocked_am']) * 100, 1) : null;
        $r['avg_in_txt'] = $r['avg_in'] ? sprintf('%02d:%02d', floor($r['avg_in']), round(fmod($r['avg_in'], 1) * 60)) : null;
        foreach (['n', 'attended', 'absent', 'leave', 'late', 'clocked_am', 'staff'] as $k) if (isset($r[$k])) $r[$k] = (int) $r[$k];
        $r['avg_h'] = $r['avg_h'] !== null ? (float) $r['avg_h'] : null;
        return $r;
    }

    private function trend(Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        return array_map(fn ($r) => $this->derive((array) $r) + ['date' => $r->date],
            DB::select("SELECT date::text, {$this->agg()} FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b) . " GROUP BY date ORDER BY date", $b));
    }

    private function weekday(Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $rows = DB::select("SELECT extract(dow FROM date)::int d, {$this->agg()} FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b) . " GROUP BY 1 ORDER BY 1", $b);
        return array_map(fn ($r) => $this->derive((array) $r) + ['day' => $names[$r->d], 'dow' => $r->d], $rows);
    }

    private function groupBy(string $col, Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        $rows = DB::select("SELECT $col k, {$this->agg()}, count(DISTINCT emp_id) staff FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b) . " GROUP BY 1 ORDER BY 1", $b);
        return array_map(fn ($r) => $this->derive((array) $r) + ['key' => $r->k], $rows);
    }

    private function arrivals(Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        $rows = DB::select("SELECT (extract(hour FROM tin)*4 + floor(extract(minute FROM tin)/15))::int slot, count(*) c
            FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b) . " AND tin >= '05:00' AND tin < '11:00' GROUP BY 1 ORDER BY 1", $b);
        $out = [];
        for ($s = 20; $s < 44; $s++) $out[$s] = ['slot' => sprintf('%02d:%02d', intdiv($s, 4), ($s % 4) * 15), 'count' => 0];
        foreach ($rows as $r) if (isset($out[$r->slot])) $out[$r->slot]['count'] = (int) $r->c;
        return array_values($out);
    }

    /** Individual patterns: chronic lateness, absence, missing clock-outs, long / short days. */
    private function people(Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        $rows = DB::select("SELECT emp_id, code, name, role, office, {$this->agg()},
              count(*) FILTER (WHERE status IN ('present','late') AND tin IS NULL) no_in,
              count(*) FILTER (WHERE tin IS NOT NULL AND tout IS NULL) no_out,
              count(*) FILTER (WHERE hours > 11) long_days, count(*) FILTER (WHERE hours < 7) short_days,
              string_agg(CASE WHEN late_arr THEN to_char(date,'Dy') END, ',') late_days
            FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b) . " GROUP BY emp_id, code, name, role, office", $b);
        $out = [];
        foreach ($rows as $r) {
            $d = $this->derive((array) $r);
            $flags = []; $score = 0;
            if ($d['late'] >= 4) { $flags[] = ['k' => 'late', 't' => "Late {$d['late']}×"]; $score += $d['late'] * 2; }
            if ($d['absent'] >= 2) { $flags[] = ['k' => 'absent', 't' => "Absent {$d['absent']}×"]; $score += $d['absent'] * 4; }
            if ($r->no_out >= 3) { $flags[] = ['k' => 'no_out', 't' => "No clock-out {$r->no_out}×"]; $score += $r->no_out; }
            if ($r->no_in >= 2) { $flags[] = ['k' => 'no_in', 't' => "Present without clock-in {$r->no_in}×"]; $score += $r->no_in; }
            if ($r->long_days >= 5) { $flags[] = ['k' => 'long', 't' => "{$r->long_days} days > 11h (fatigue risk)"]; $score += $r->long_days; }
            if ($d['leave'] >= 10) { $flags[] = ['k' => 'leave', 't' => "On leave {$d['leave']} days"]; $score += 2; }
            if (!$flags) continue;
            $days = array_count_values(array_filter(explode(',', (string) $r->late_days)));
            arsort($days);
            $out[] = ['emp_id' => $r->emp_id, 'code' => $r->code, 'name' => $r->name, 'role' => strtoupper($r->role), 'office' => $r->office,
                'rate' => $d['rate'], 'late' => $d['late'], 'absent' => $d['absent'], 'leave' => $d['leave'], 'avg_in' => $d['avg_in_txt'],
                'avg_h' => $d['avg_h'], 'no_out' => (int) $r->no_out, 'no_in' => (int) $r->no_in, 'flags' => $flags, 'score' => $score,
                'late_pattern' => $days ? array_key_first($days) . " ({$days[array_key_first($days)]}×)" : null];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private function quality(Carbon $from, Carbon $to, ?string $office): array
    {
        $b = [];
        $r = DB::selectOne("SELECT count(*) FILTER (WHERE status IN ('present','late') AND tin IS NULL) present_no_in,
              count(*) FILTER (WHERE tin IS NOT NULL AND tout IS NULL) no_out,
              count(*) FILTER (WHERE status='absent' AND tin IS NOT NULL) absent_with_in,
              count(*) FILTER (WHERE status IS NULL) no_status,
              count(*) FILTER (WHERE status='late' AND tin <= '" . self::LATE_AFTER . "') late_but_early
            FROM ({$this->base()}) x" . $this->where($from, $to, $office, $b), $b);
        $r = array_map('intval', (array) $r);
        $r['total'] = array_sum($r);
        return $r;
    }

    /* ── actions ──────────────────────────────────────────────────────── */
    private function actions(array $c, array $p, array $wd, array $off, array $people, array $q): array
    {
        $A = [];
        $add = function ($sev, $cat, $title, $why, $action, $owner, $emp = null) use (&$A) {
            $A[] = compact('sev', 'cat', 'title', 'why', 'action', 'owner', 'emp') + ['id' => 'a' . md5($cat . $title)];
        };
        $names = fn ($list, $n = 5) => implode(', ', array_map(fn ($x) => "{$x['name']} ({$x['code']})", array_slice($list, 0, $n))) . (count($list) > $n ? ' …' : '');

        if ($c['rate'] < $p['rate'] - 3)
            $add('high', 'Trend', "Attendance fell to {$c['rate']}% (was {$p['rate']}%)", "Absences {$p['absent']} → {$c['absent']}, leave {$p['leave']} → {$c['leave']} in the comparison period",
                'Review leave approvals and absence reasons with supervisors this week', 'HR / Manager');

        $chronicLate = array_values(array_filter($people, fn ($x) => $x['late'] >= 4));
        if ($chronicLate) $add(count($chronicLate) >= 5 ? 'high' : 'medium', 'Punctuality', count($chronicLate) . ' staff late 4+ times',
            $names($chronicLate) . ' — arrived after ' . substr(self::LATE_AFTER, 0, 5),
            'Issue reminders; for repeat cases hold a counselling session and check shift/transport issues', 'Supervisor');

        $absentees = array_values(array_filter($people, fn ($x) => $x['absent'] >= 2));
        if ($absentees) $add('high', 'Absence', count($absentees) . ' staff absent 2+ times', $names($absentees),
            'Verify reasons (MC / emergency) and apply disciplinary procedure for unexcused absence', 'HR');

        $longDays = array_values(array_filter($people, fn ($x) => collect($x['flags'])->contains('k', 'long')));
        if ($longDays) $add('medium', 'Wellbeing', count($longDays) . ' staff regularly work > 11 hours', $names($longDays),
            'Check workload and overtime claims; rotate duties to reduce fatigue risk', 'Manager');

        $worstDay = collect($wd)->filter(fn ($d) => $d['n'] > 20)->sortBy('punctual')->first();
        if ($worstDay && $worstDay['punctual'] !== null && $c['punctual'] !== null && $worstDay['punctual'] < $c['punctual'] - 5)
            $add('low', 'Pattern', "{$worstDay['day']} has the worst punctuality ({$worstDay['punctual']}%)", "Average across the week is {$c['punctual']}%",
                "Schedule briefings / toolbox talks early on {$worstDay['day']} to anchor arrival time", 'Supervisor');

        $worstOff = collect($off)->sortBy('rate')->first();
        $bestOff = collect($off)->sortByDesc('rate')->first();
        if ($worstOff && $bestOff && $bestOff['rate'] - $worstOff['rate'] >= 3)
            $add('medium', 'Office', "{$worstOff['key']} attendance {$worstOff['rate']}% vs {$bestOff['key']} {$bestOff['rate']}%", "{$worstOff['absent']} absences, {$worstOff['leave']} leave days at {$worstOff['key']}",
                "Review {$worstOff['key']} roster and leave planning", 'Manager');

        if ($q['no_out'] + $q['present_no_in'] >= 10)
            $add('medium', 'Data quality', ($q['no_out'] + $q['present_no_in']) . ' incomplete clock records',
                "{$q['no_out']} without clock-out, {$q['present_no_in']} marked present without clock-in",
                'Check time-clock devices / app at the affected offices and remind staff to clock out', 'IT / HR');
        if ($q['absent_with_in'] + $q['late_but_early'] + $q['no_status'] > 0)
            $add('low', 'Data quality', ($q['absent_with_in'] + $q['late_but_early'] + $q['no_status']) . ' contradictory attendance records',
                "{$q['absent_with_in']} absent but clocked in · {$q['late_but_early']} marked late but arrived before " . substr(self::LATE_AFTER, 0, 5) . " · {$q['no_status']} with no status",
                'Correct the status of these records before payroll', 'HR');

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($A, fn ($a, $b) => $rank[$a['sev']] <=> $rank[$b['sev']]);
        return $A;
    }

    private function pct($a, $b) { return $b ? round(($a - $b) / $b * 100, 1) : null; }

    private function kpis(array $c, array $p, array $trend): array
    {
        $sp = fn ($k) => array_map(fn ($t) => $t[$k], array_slice($trend, -30));
        $k = fn ($key, $label, $unit, $v, $pv, $good, $hint, $spark = []) =>
            compact('key', 'label', 'unit', 'good', 'hint', 'spark') + ['value' => $v, 'prev' => $pv,
                'delta' => is_numeric($v) && is_numeric($pv) ? round($v - $pv, 1) : null];
        return [
            $k('rate', 'Attendance rate', '%', $c['rate'], $p['rate'], 'up', 'Present ÷ (records − on leave)', $sp('rate')),
            $k('punctual', 'On-time arrival', '%', $c['punctual'], $p['punctual'], 'up', 'Clock-in at or before ' . substr(self::LATE_AFTER, 0, 5), $sp('punctual')),
            $k('avg_in', 'Avg. clock-in', '', $c['avg_in_txt'], $p['avg_in_txt'], null, 'Average morning clock-in time'),
            $k('avg_h', 'Avg. hours / day', 'h', $c['avg_h'], $p['avg_h'], 'up', 'Clock-out − clock-in', $sp('avg_h')),
            $k('late', 'Late arrivals', '', $c['late'], $p['late'], 'down', 'Clock-ins after ' . substr(self::LATE_AFTER, 0, 5) . ' and before 12:00', $sp('late')),
            $k('absent', 'Absences', '', $c['absent'], $p['absent'], 'down', 'Records with status absent', $sp('absent')),
            $k('leave', 'Leave days', '', $c['leave'], $p['leave'], null, 'Records with status on leave', $sp('leave')),
            $k('staff', 'Staff tracked', '', $c['staff'], $p['staff'], null, 'Distinct staff with records in the period'),
        ];
    }

    private function summary(array $c, array $p, array $wd, array $off, array $people, array $q, array $actions): array
    {
        $what = [
            abs($c['rate'] - $p['rate']) < 0.1
                ? sprintf('Attendance %s%% over %d staff — unchanged; %s', $c['rate'], $c['staff'], $c['absent'] ? "{$c['absent']} absences" : 'no unexcused absences recorded')
                : sprintf('Attendance %s%% over %d staff (%s %s pts vs previous period)', $c['rate'], $c['staff'], $c['rate'] >= $p['rate'] ? 'up' : 'down', abs(round($c['rate'] - $p['rate'], 1))),
            sprintf('%s%% arrive on time; average clock-in %s, average day %s h', $c['punctual'] ?? '—', $c['avg_in_txt'] ?? '—', $c['avg_h'] ?? '—'),
            sprintf('%d late arrivals, %d absences, %d leave days in the period', $c['late'], $c['absent'], $c['leave']),
        ];
        $why = [];
        $late = collect($people)->sum('late');
        if ($late && $c['late']) {
            $top = collect($people)->sortByDesc('late')->take(5)->sum('late');
            $why[] = sprintf('Lateness is concentrated: the top 5 staff account for %d%% of late arrivals', round($top / $c['late'] * 100));
        }
        $wdw = collect($wd)->filter(fn ($d) => $d['n'] > 20 && $d['punctual'] !== null)->sortBy('punctual')->first();
        if ($wdw) $why[] = "{$wdw['day']} is the weakest day for punctuality ({$wdw['punctual']}% on time)";
        $wo = collect($off)->sortBy('rate')->first();
        $bo = collect($off)->sortByDesc('rate')->first();
        if ($wo && $bo && $bo['rate'] - $wo['rate'] >= 1) $why[] = "{$wo['key']} has the lowest attendance rate ({$wo['rate']}%)";
        else {
            $wp = collect($off)->filter(fn ($o) => $o['punctual'] !== null)->sortBy('punctual')->first();
            $bp = collect($off)->filter(fn ($o) => $o['punctual'] !== null)->sortByDesc('punctual')->first();
            if ($wp && $bp && $bp['punctual'] - $wp['punctual'] >= 3) $why[] = "{$wp['key']} is least punctual ({$wp['punctual']}% on time vs {$bp['key']} {$bp['punctual']}%)";
        }
        if ($q['total']) $why[] = "{$q['total']} records are incomplete or contradictory — some 'late' and 'missing' figures in the old view were understated";
        $sev = array_count_values(array_column($actions, 'sev'));
        return ['what' => $what, 'why' => $why, 'do' => array_map(fn ($a) => $a['action'] . ' — ' . $a['title'], array_slice($actions, 0, 3)),
            'counts' => ['critical' => $sev['critical'] ?? 0, 'high' => $sev['high'] ?? 0, 'medium' => $sev['medium'] ?? 0, 'low' => $sev['low'] ?? 0],
            'tone' => $c['rate'] < $p['rate'] - 3 || ($sev['high'] ?? 0) >= 2 ? 'bad' : ($c['rate'] >= 90 ? 'good' : 'neutral')];
    }
}

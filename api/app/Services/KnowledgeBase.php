<?php

namespace App\Services;

use App\Http\Controllers\ApiController;
use App\Http\Controllers\AttendanceInsightsController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\MonitoringInsightsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge base: stores the decision-intelligence output of every page as
 * readable text (auto snapshots), fixed reference entries (definitions and
 * rules), and notes users add. Retrieval is PostgreSQL full-text search.
 */
class KnowledgeBase
{
    public const PAGES = ['map', 'dashboard', 'monitoring', 'attendance', 'general', 'hr', 'ess'];

    /** HR policies for the self-service portal. Statutory figures follow the Employment Act 1955 (2022 amendment);
     *  everything marked TEMPLATE is a placeholder for KSSB's own policy and must be confirmed by HR. */
    public function seedHrPolicies(): int
    {
        $p = [
            ['hr-leave', 'Polisi Cuti / Leave policy', <<<'T'
STATUS: Statutory minimums from the Employment Act 1955 (amended 2022). Company-specific rules are marked TEMPLATE — confirm with HR.

Annual leave (cuti tahunan) — per completed year of service: less than 2 years: 8 days; 2 to 5 years: 12 days; more than 5 years: 16 days. Pro-rated in the first year. Apply at least 3 days in advance (TEMPLATE). Approval: supervisor then HR.
Sick leave (cuti sakit / MC) — less than 2 years: 14 days; 2 to 5 years: 18 days; more than 5 years: 22 days per year, with a medical certificate from a registered doctor. Inform your supervisor on the same day; submit in ESS within 7 days (TEMPLATE).
Hospitalisation leave (cuti hospital) — up to 60 days per year when hospitalisation is certified (total sick + hospitalisation capped at 60 days). Approval: HR.
Maternity leave (cuti bersalin) — 98 consecutive days. Paternity leave (cuti paterniti) — 7 consecutive days for married male employees (with at least 12 months of service, up to 5 confinements). Approval: HR.
Emergency leave (cuti kecemasan) — deducted from annual leave balance; may be applied up to 3 days after the event (TEMPLATE). Approval: supervisor.
Compassionate leave (cuti ihsan) — 3 days for death of immediate family (TEMPLATE). Replacement leave (cuti gantian) — earned by working on a rest day or public holiday when approved instead of overtime pay (TEMPLATE). Unpaid leave — at management discretion; supervisor then HR.
Leave days exclude rest days and gazetted public holidays. Half-day leave (AM/PM) counts as 0.5 day.
T],
            ['hr-overtime', 'Polisi Kerja Lebih Masa / Overtime policy', <<<'T'
STATUS: Rates and the monthly cap follow the Employment Act 1955; approval flow is TEMPLATE.

Overtime must be pre-approved by your supervisor and recorded in ESS within 3 days (TEMPLATE).
Rates (hourly rate = monthly wage / 26 / normal hours per day):
- Normal working day: 1.5 x hourly rate for each hour beyond normal hours.
- Rest day: 2.0 x hourly rate (for work beyond normal hours on a rest day).
- Public holiday: 3.0 x hourly rate (for work beyond normal hours on a public holiday).
Limit: overtime may not exceed 104 hours in a month.
Approval: supervisor then HR. Overtime on a rest day may be converted to replacement leave if the employee chooses (TEMPLATE).
T],
            ['hr-timeoff', 'Polisi Time-off (keluar sementara) / Time-off policy', <<<'T'
STATUS: TEMPLATE — company rule, confirm with HR.
Time-off is a short absence during working hours for personal matters (bank, clinic, school, government office).
- Maximum 4 hours per slip and 2 slips per month.
- Apply in ESS before leaving; supervisor approval required.
- Time-off beyond the limit is deducted as half-day annual leave.
- Clock out and clock back in when leaving the site for more than 1 hour.
T],
            ['hr-attendance', 'Waktu Kerja & Kehadiran / Working hours & attendance', <<<'T'
STATUS: Statutory limits from the Employment Act 1955; office times are TEMPLATE.
- Normal hours may not exceed 8 hours a day or 45 hours a week (Employment Act).
- Clock in and clock out every working day through ESS (GPS location is recorded). Forgetting to clock out must be corrected through your supervisor.
- The analytics treat a clock-in after 08:30 as late (TEMPLATE threshold — confirm the official start time with HR).
- Repeated lateness (4 or more times a month) is flagged to the supervisor for counselling.
- Absence without approved leave or a medical certificate is unauthorised absence; absence for more than 2 consecutive working days without notice may be treated as breach of contract under the Employment Act.
T],
            ['hr-claims', 'Polisi Tuntutan & Kewangan / Claims & finance policy', <<<'T'
STATUS: TEMPLATE — rates and limits must be set by Finance/HR. No payroll data is stored in this system.
Claim categories: mileage (own vehicle), travel & accommodation, meals during outstation work, medical (outpatient), telephone, and others.
- Submit claims in ESS within 30 days of the expense with receipts.
- Approval: supervisor then HR/Finance. Approved claims are paid with the next salary (TEMPLATE).
- Mileage and meal allowance rates, and the outpatient medical limit per year, are set by Finance — ask HR for the current rates.
- Salary is paid monthly; wages must be paid no later than the 7th day after the end of the wage period (Employment Act).
- Statutory deductions: EPF (KWSP), SOCSO (PERKESO), EIS (SIP) and PCB income tax.
T],
            ['hr-conduct', 'Kod Etika & Keselamatan / Code of conduct & safety', <<<'T'
STATUS: TEMPLATE — summary; refer to the company handbook.
- Wear PPE (safety helmet, safety boots, high-visibility vest) at all mining, quarry and stockpile sites.
- Report accidents, near-misses and environmental incidents (turbid effluent, spillway failure) to the site officer immediately.
- Do not alter dockets or weighbridge records; corrections go through the docket correction report.
- Sexual harassment complaints can be lodged with HR; the company must investigate under Part XV of the Employment Act.
- Use of company vehicles and equipment is for official work only.
T],
        ];
        foreach ($p as [$slug, $title, $body]) $this->upsert($slug, 'hr', 'reference', $title, $body, ['hr', 'policy']);
        return count($p);
    }

    /* ── retrieval ────────────────────────────────────────────────────── */

    /** Best-matching entries for a question; the page's own snapshots are always included. */
    /** Question words minus stop-words, expanded with English synonyms for Malay terms. */
    public function terms(string $q): \Illuminate\Support\Collection
    {
        return collect(preg_split('/[^\p{L}\p{N}\/]+/u', mb_strtolower($q)))
            ->filter(fn ($w) => mb_strlen($w) >= 2 && !in_array($w, self::STOP, true))
            ->flatMap(fn ($w) => array_merge([$w], self::SYN[$w] ?? []))   // Malay question → English KB content
            ->unique()->values();
    }

    /** @param array|null $pages restrict to these pages (staff-only users may read HR/general entries only) */
    public function search(string $q, ?string $page, int $limit = 6, ?array $pages = null): array
    {
        $terms = $this->terms($q);
        $pf = $pages ? " AND page IN ('" . implode("','", array_map(fn ($x) => preg_replace('/[^a-z]/', '', $x), $pages)) . "')" : '';
        $page = $page === 'ess' ? 'hr' : $page;   // self-service questions lean on HR policy entries
        $hits = collect();
        if ($terms->isNotEmpty()) {
            // OR-match so a Malay question still finds English content sharing site codes / numbers / keywords
            $tsq = $terms->map(fn ($w) => preg_replace('/[^\p{L}\p{N}]/u', '', $w))->filter()->map(fn ($w) => "$w:*")->implode(' | ');
            if ($tsq !== '') {
                $hits = collect(DB::select("SELECT id, title, page, source, slug, content, updated_at,
                        ts_rank_cd(search, to_tsquery('simple', ?)) + CASE WHEN page = ? THEN 0.15 ELSE 0 END AS rank
                    FROM knowledge_base WHERE search @@ to_tsquery('simple', ?)$pf ORDER BY rank DESC LIMIT ?", [$tsq, $page ?? '', $tsq, $limit]));
            }
        }
        if ($page && !$hits->contains('page', $page) && (!$pages || in_array($page, $pages, true))) {
            // the page's newest snapshot (slugs sort chronologically, e.g. auto-dashboard-2026-11)
            $own = collect(DB::select("SELECT id, title, page, source, slug, content, updated_at, 0 AS rank FROM knowledge_base
                WHERE page = ? AND source = 'auto' ORDER BY slug DESC LIMIT 1", [$page]));
            $hits = $hits->concat($own)->unique('id');
        }
        if ($hits->count() < 3) {   // always give the model some general grounding
            $ref = collect(DB::select("SELECT id, title, page, source, slug, content, updated_at, 0 AS rank FROM knowledge_base WHERE source = 'reference'$pf
                ORDER BY (page = ?) DESC, id LIMIT 3", [$page ?? '']));
            $hits = $hits->concat($ref)->unique('id');
        }
        return $hits->take($limit + 2)->values()->map(fn ($r) => (array) $r)->all();
    }

    private const SYN = [
        'jualan' => ['sales'], 'jual' => ['sales'], 'turun' => ['down', 'fell'], 'jatuh' => ['down', 'fell'], 'naik' => ['up'], 'meningkat' => ['up'],
        'tapak' => ['site', 'sites'], 'lewat' => ['late'], 'staf' => ['staff'], 'pekerja' => ['staff'], 'kakitangan' => ['staff'],
        'cuti' => ['leave'], 'hadir' => ['attendance', 'present'], 'kehadiran' => ['attendance'], 'ponteng' => ['absent', 'absence'],
        'dokumen' => ['document', 'documents'], 'hujan' => ['rain'], 'cuaca' => ['weather', 'rain'], 'doket' => ['docket'], 'lori' => ['lorry', 'trips'],
        'tamat' => ['expired', 'expire'], 'luput' => ['expired'], 'pejabat' => ['office'], 'tindakan' => ['action'], 'punca' => ['cause', 'causes', 'why'],
        'sebab' => ['cause', 'why'], 'jam' => ['hours'], 'masa' => ['time', 'hours'], 'kelulusan' => ['approval'], 'lulus' => ['approval', 'approve'],
        'tuan' => ['landowner'], 'tanah' => ['landowner', 'land'], 'pemilik' => ['landowner'], 'agensi' => ['agency'], 'lawatan' => ['visit', 'visits'],
        'rosak' => ['down', 'breakdown'], 'sistem' => ['system'], 'skor' => ['score'], 'kesihatan' => ['health'], 'wilayah' => ['region'],
        'utara' => ['northern', 'psu'], 'selatan' => ['southern', 'pss'], 'kontraktor' => ['contractor'], 'ramalan' => ['forecast', 'projected'],
        'bulan' => ['month'], 'minggu' => ['week'], 'hari' => ['day', 'days'], 'paling' => ['most'], 'kerap' => ['most'], 'banyak' => ['most'],
        'baki' => ['available', 'balance'], 'gaji' => ['salary', 'wage', 'wages', 'paid'], 'dibayar' => ['paid'], 'bayar' => ['paid', 'payment'],
        'kadar' => ['rate', 'rates'], 'umum' => ['public', 'holiday'], 'ot' => ['overtime'], 'tuntutan' => ['claim', 'claims'], 'tuntut' => ['claim'],
        'elaun' => ['allowance', 'mileage', 'meal'], 'perubatan' => ['medical'], 'mc' => ['sick', 'medical'], 'sakit' => ['sick'], 'kecemasan' => ['emergency'],
        'bersalin' => ['maternity'], 'paterniti' => ['paternity'], 'hospital' => ['hospitalisation'], 'polisi' => ['policy'], 'had' => ['limit', 'maximum'],
        'kwsp' => ['epf'], 'perkeso' => ['socso'], 'cukai' => ['tax', 'pcb'], 'keselamatan' => ['safety', 'ppe'], 'gangguan' => ['harassment'],
        'pengeluaran' => ['production'], 'pematuhan' => ['compliance'], 'risiko' => ['risk'], 'laporan' => ['report', 'reports'],
    ];

    private const STOP = ['the', 'and', 'for', 'what', 'why', 'how', 'is', 'are', 'of', 'to', 'in', 'on', 'a', 'an', 'apa', 'kenapa', 'bagaimana',
        'macam', 'mana', 'yang', 'yg', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'ada', 'tak', 'tidak', 'saya', 'kita', 'boleh', 'blh', 'ni', 'tu',
        'dengan', 'untuk', 'utk', 'pada', 'adalah', 'berapa', 'siapa', 'bila', 'show', 'me', 'tell', 'about', 'which', 'who', 'does', 'do'];

    /* ── snapshots ────────────────────────────────────────────────────── */

    public function refreshAll(): array
    {
        $out = [];
        foreach (['dashboard', 'monitoring', 'attendance', 'map'] as $p) $out[$p] = $this->refresh($p);
        $out['reference'] = $this->seedReferences();
        return $out;
    }

    public function refresh(string $page): int
    {
        return match ($page) {
            'dashboard' => $this->snapDashboard(),
            'monitoring' => $this->upsert('auto-monitoring', 'monitoring', 'auto', 'Monitoring & compliance insights (latest)', $this->renderMonitoring()),
            'attendance' => $this->snapAttendance(),
            'map' => $this->upsert('auto-map', 'map', 'auto', 'Site map — permit status of every site (latest)', $this->renderMap()),
            default => 0,
        };
    }

    /** Refresh a page's snapshot if it is missing or older than $hours. */
    public function ensureFresh(?string $page, int $hours = 6): void
    {
        if (!$page || $page === 'general') return;
        $last = DB::table('knowledge_base')->where('page', $page)->where('source', 'auto')->max('updated_at');
        if (!$last || now()->diffInHours($last) >= $hours) $this->refresh($page);
        if (!DB::table('knowledge_base')->where('source', 'reference')->exists()) $this->seedReferences();
    }

    private function upsert(string $slug, string $page, string $source, string $title, string $content, array $tags = []): int
    {
        $row = ['title' => $title, 'page' => $page, 'source' => $source, 'content' => $content, 'tags' => json_encode($tags), 'updated_at' => now()];
        $id = DB::table('knowledge_base')->where('slug', $slug)->value('id');
        if ($id) { DB::table('knowledge_base')->where('id', $id)->update($row); return (int) $id; }
        return (int) DB::table('knowledge_base')->insertGetId($row + ['slug' => $slug, 'created_at' => now()]);
    }

    private function call(object $controller, array $params): array
    {
        return json_decode(json_encode($controller->index(new Request($params))), true);
    }

    /* dashboard: one entry per month with data (so "why did September go up?" is answerable) */
    private function snapDashboard(): int
    {
        $ctl = new InsightsController();
        $months = $this->call($ctl, [])['period']['available'];
        $n = 0;
        foreach ($months as $m) {
            $d = $this->call($ctl, ['year' => $m['year'], 'month' => $m['month']]);
            $this->upsert(sprintf('auto-dashboard-%d-%02d', $m['year'], $m['month']), 'dashboard', 'auto',
                "Operations & sales insights — {$d['period']['label']}" . ($d['period']['partial'] ? ' (month to date)' : ''),
                $this->renderDashboard($d), ['sales', 'month:' . $m['label']]);
            $n++;
        }
        return $n;
    }

    private function snapAttendance(): int
    {
        $ctl = new AttendanceInsightsController();
        foreach ([30, 90] as $days)
            $this->upsert("auto-attendance-{$days}d", 'attendance', 'auto', "Attendance insights — last {$days} days",
                $this->renderAttendance($this->call($ctl, ['days' => $days])), ['attendance']);
        return 2;
    }

    /* ── renderers (plain text the model and people can read) ─────────── */

    private function lines(array $xs, string $bullet = '- '): string { return implode("\n", array_map(fn ($x) => $bullet . $x, $xs)); }
    private function n($v, int $d = 0): string { return $v === null ? '—' : number_format((float) $v, $d); }

    private function actionsText(array $actions, int $max = 15): string
    {
        return implode("\n", array_map(fn ($a) => sprintf("- [%s] %s (owner: %s)\n  Why: %s\n  Action: %s%s", strtoupper($a['sev']), $a['title'], $a['owner'],
            $a['why'], $a['action'], !empty($a['impact']) ? "\n  Impact: {$a['impact']}" : ''), array_slice($actions, 0, $max)));
    }

    private function kpiText(array $kpis): string
    {
        return implode("\n", array_map(function ($k) {
            $d = $k['delta_pct'] ?? $k['delta'] ?? null;
            return sprintf('- %s: %s %s%s', $k['label'], is_numeric($k['value'] ?? null) ? $this->n($k['value'], 1) : ($k['value'] ?? '—'), $k['unit'] ?? '',
                $d !== null ? sprintf(' (previous %s, change %s%s)', is_numeric($k['prev'] ?? null) ? $this->n($k['prev'], 1) : ($k['prev'] ?? '—'), $d > 0 ? '+' : '', $d . (isset($k['delta_pct']) ? '%' : '')) : '');
        }, $kpis));
    }

    private function renderDashboard(array $d): string
    {
        $p = $d['period'];
        $s = "PERIOD: {$p['label']} ({$p['from']} to {$p['to']}), compared with {$p['prev_label']}.\n\n";
        $s .= "WHAT HAPPENED\n" . $this->lines($d['summary']['what']) . "\n\nWHY\n" . $this->lines($d['summary']['why']) . "\n\n";
        $s .= "KPIs\n" . $this->kpiText($d['kpis']) . "\n\n";
        $s .= "MONTHLY SALES TREND (MT, implausible entries excluded)\n" . $this->lines(array_map(fn ($t) => "{$t['label']}: " . $this->n($t['sales']) . " MT, {$t['trips']} trips, " . $this->n($t['op_h']) . ' operating hours', array_filter($d['trend'], fn ($t) => $t['report_days']))) . "\n\n";
        if ($d['forecast']) $s .= sprintf("FORECAST: month-to-date %s MT after %d of %d days; projected month end %s MT vs last month %s MT.\n\n",
            $this->n($d['forecast']['mtd']), $d['forecast']['days_elapsed'], $d['forecast']['days_in_month'], $this->n($d['forecast']['projected']), $this->n($d['forecast']['prev_month_total']));
        $s .= "SALES CHANGE BY SITE (biggest contributors, with causes)\n" . $this->lines(array_map(fn ($x) => sprintf('%s (%s): %s MT vs %s MT (%s%s MT, %s%%). Causes: %s',
            $x['code'], $x['region'], $this->n($x['sales']), $this->n($x['prev_sales']), $x['delta'] >= 0 ? '+' : '', $this->n($x['delta']), $x['delta_pct'] ?? 'n/a',
            implode('; ', array_column($x['factors'], 'text'))), array_slice($d['drivers'], 0, 20))) . "\n\n";
        $s .= "SITE HEALTH (lowest scores first, 100 = healthy)\n" . $this->lines(array_map(fn ($h) => sprintf('%s — score %d (%s): %s. Sales %s MT, permit %s',
            $h['code'], $h['score'], $h['grade'], $h['issues'] ? implode(', ', $h['issues']) : 'no issues', $this->n($h['sales']),
            $h['days_left'] === null ? 'no date' : ($h['days_left'] < 0 ? 'expired' : "{$h['days_left']} days left")), array_slice($d['health'], 0, 25))) . "\n\n";
        $b = $d['backlog'];
        $s .= sprintf("F001 APPROVAL BACKLOG: draft %d, pending review %d (%d overdue), pending approval %d (%d overdue), returned %d.\n\n",
            $b['draft']['count'], $b['pending_review']['count'], $b['pending_review']['overdue'], $b['pending_approval']['count'], $b['pending_approval']['overdue'], $b['returned']['count']);
        if ($d['anomalies']) $s .= "IMPLAUSIBLE SALES ENTRIES (excluded)\n" . $this->lines(array_map(fn ($a) => "{$a['code']} {$a['date']}: " . $this->n($a['sales']) . " MT on {$a['trips']} trips ({$a['per_trip']} MT/trip)", $d['anomalies'])) . "\n\n";
        return $s . "RECOMMENDED ACTIONS\n" . $this->actionsText($d['actions']);
    }

    private function renderMonitoring(): string
    {
        $d = $this->call(new MonitoringInsightsController(), []);
        $s = "AS OF {$d['as_of']}. Operating sites = sites with daily reports in the last 60 days.\n\n";
        $s .= "STATUS\n" . $this->lines($d['summary']['what']) . "\n\nWHY\n" . $this->lines($d['summary']['why']) . "\n\nKPIs\n";
        $s .= $this->lines(array_map(fn ($k) => "{$k['label']}: {$k['value']} {$k['unit']}" . (isset($k['of']) ? " of {$k['of']}" : '') . " — {$k['hint']}", $d['kpis'])) . "\n\n";
        $s .= "DOCUMENT COVERAGE (operating sites)\n" . $this->lines(array_map(fn ($c) => "{$c['label']}" . ($c['required'] ? '' : ' (optional)') . ": {$c['ok']} current, {$c['stale']} outdated" . ($c['max_age'] ? " (> {$c['max_age']} days)" : '') . ", {$c['missing']} missing", $d['documents']['categories'])) . "\n\n";
        $s .= "SITE DOCUMENT GAPS (score = % of required documents current)\n" . $this->lines(array_map(function ($r) {
            $gaps = array_keys(array_filter($r['cells'], fn ($c) => $c['required'] && $c['state'] !== 'ok'));
            return "{$r['code']}: score {$r['score']}" . ($gaps ? ' — gaps: ' . implode(', ', array_map(fn ($g) => $g . ' (' . $r['cells'][$g]['state'] . ')', $gaps)) : ' — complete');
        }, $d['documents']['rows'])) . "\n\n";
        $s .= "DOCKET INCIDENTS: {$d['dockets']['total']} reports. Causes: " . implode(', ', array_map(fn ($c) => "{$c['label']} {$c['count']}", $d['dockets']['causes'])) . ". Repeat sites: " . implode(', ', array_map(fn ($x) => "{$x['code']} x{$x['count']}", $d['dockets']['by_site'])) . "\n";
        $s .= $this->lines(array_map(fn ($i) => "{$i['date']} {$i['code']} {$i['type']} — {$i['cause']}: {$i['issue']}", array_slice($d['dockets']['items'], 0, 20))) . "\n\n";
        $s .= "SYSTEM DOWNTIME, LAST 30 DAYS (days)\n" . $this->lines(array_map(fn ($x) => "{$x['code']}: e-Docket {$x['edocket']}, Gemma {$x['gemma']}, CCTV {$x['cctv']}", $d['dockets']['downtime'])) . "\n\n";
        $lo = $d['landowner'];
        $s .= "LANDOWNER MONTHLY REPORTS: {$lo['pending']} of {$lo['total']} not approved, {$lo['over_60']} older than 60 days. By site: " . implode(', ', array_map(fn ($x) => "{$x['code']} {$x['count']} pending ({$x['age_days']}d)", $lo['by_site'])) . "\n\n";
        $s .= "AGENCY MENTIONS (90 days): " . json_encode($d['visits']['by_agency']) . ". Recent remarks:\n" . $this->lines(array_map(fn ($v) => "{$v['date']} {$v['code']}: {$v['text']}", array_slice($d['visits']['items'], 0, 15))) . "\n\n";
        return $s . "RECOMMENDED ACTIONS\n" . $this->actionsText($d['actions']);
    }

    private function renderAttendance(array $d): string
    {
        $p = $d['period'];
        $s = "PERIOD: {$p['from']} to {$p['to']} ({$p['days']} days) vs {$p['prev_from']} to {$p['prev_to']}. Late = clock-in after {$p['late_after']} and before 12:00.\n\n";
        $s .= "WHAT HAPPENED\n" . $this->lines($d['summary']['what']) . "\n\nWHY\n" . $this->lines($d['summary']['why']) . "\n\nKPIs\n" . $this->kpiText($d['kpis']) . "\n\n";
        $s .= "BY WEEKDAY\n" . $this->lines(array_map(fn ($w) => "{$w['day']}: attendance {$w['rate']}%, on time {$w['punctual']}%, {$w['late']} late, {$w['leave']} leave", $d['weekday'])) . "\n\n";
        $s .= "BY OFFICE (HQ = headquarters, PSU = Pejabat Satelit Utara, PSS = Pejabat Satelit Selatan)\n" . $this->lines(array_map(fn ($o) => "{$o['key']}: {$o['staff']} staff, attendance {$o['rate']}%, on time {$o['punctual']}%, avg clock-in {$o['avg_in_txt']}, avg day {$o['avg_h']} h", $d['by_office'])) . "\n\n";
        $s .= "BY ROLE\n" . $this->lines(array_map(fn ($o) => "{$o['key']}: on time {$o['punctual']}%, {$o['late']} late arrivals, avg clock-in {$o['avg_in_txt']}", $d['by_role'])) . "\n\n";
        $s .= "STAFF WATCHLIST\n" . $this->lines(array_map(fn ($x) => "{$x['name']} ({$x['code']}, {$x['role']}, {$x['office']}): " . implode(', ', array_column($x['flags'], 't')) . "; avg clock-in {$x['avg_in']}" . ($x['late_pattern'] ? "; mostly late on {$x['late_pattern']}" : ''), array_slice($d['people'], 0, 30))) . "\n\n";
        $q = $d['quality'];
        $s .= "DATA QUALITY: {$q['total']} records need correction — {$q['present_no_in']} present without clock-in, {$q['no_out']} without clock-out, {$q['late_but_early']} marked late but early, {$q['no_status']} without status, {$q['absent_with_in']} absent but clocked in.\n\n";
        return $s . "RECOMMENDED ACTIONS\n" . $this->actionsText($d['actions']);
    }

    private function renderMap(): string
    {
        $sites = json_decode(json_encode((new ApiController())->map()), true);
        $by = collect($sites)->groupBy('status');
        $s = 'PERMIT STATUS (as of ' . now()->toDateString() . '): ' . collect(['active', 'warning', 'expired', 'unknown'])->map(fn ($k) => "$k " . count($by[$k] ?? []))->implode(', ')
            . ". Warning = permit ends within 90 days. Total sites: " . count($sites) . ".\n\nALL SITES\n";
        return $s . $this->lines(array_map(fn ($x) => sprintf('%s — %s | %s | contractor: %s | permit %s to %s (%s) | cameras %d, drone photo points %d, boundary polygons %d | latest F001: %s',
            $x['site_code'], $x['name'], $x['region'] ?? '—', $x['contractor'] ?? '—', $x['permit_date'] ?? '—', $x['permit_date_end'] ?? '—',
            $x['days_left'] === null ? 'no end date' : ($x['days_left'] < 0 ? 'EXPIRED ' . abs($x['days_left']) . ' days ago' : "{$x['days_left']} days left"),
            $x['camera_count'], $x['photo_points'], $x['polygon_count'], $x['latest_f001'] ? "{$x['latest_f001']['status']} (week from {$x['latest_f001']['from']})" : 'none'), $sites));
    }

    /* ── reference entries: definitions the analyses rely on ─────────── */
    public function seedReferences(): int
    {
        $refs = [
            ['ref-system', 'About the Smart Monitoring System', <<<'T'
The Smart Monitoring System is used by KSSB / KS Mining & Minerals (KSMM) to supervise sand-mining, quarry, river and stockpile sites in Selangor, split into the Northern Region and Southern Region.
Pages: Map (site permit status, boundaries, drone photo points, live rain), Dashboard (sales & operations decision intelligence), Monitoring (document compliance, docket integrity, agency visits, landowner reports, report repository), Attendance (staff attendance, punctuality, watchlist).
Core record: the F001 weekly report (one per site per week) containing daily reports with weather slots, manpower, sales/docket records, weighbridge AVR readings, equipment counts, system status (CCTV, e-Docket, Gemma) and environment status (effluent, ponds, spillway).
T],
            ['ref-f001-workflow', 'F001 approval workflow and roles', <<<'T'
F001 status flow: draft -> pending_review -> pending_approval -> submitted, plus "returned" for rework.
- Site officer submits for review (draft/returned -> pending_review).
- Executive validates (pending_review -> pending_approval) or returns.
- Manager final-approves (pending_approval -> submitted) or returns.
- Super admin can override any transition. Every transition is logged in report activities.
A report waiting more than 7 days after its week ended is counted as overdue in the dashboard pipeline.
T],
            ['ref-definitions', 'Definitions used in the analyses (KPIs, scores, thresholds)', <<<'T'
Sales: sum of "jualan" (MT) in F001 daily sales records. Entries with more than 100 MT per lorry trip or more than 20,000 MT in a day are treated as implausible (likely typing errors) and excluded from all totals.
Weather slots: each day has 48 half-hour slots. "X" = operating slot, "/" = rain slot (inferred: X strongly correlates with sales). Operating hours = X slots x 0.5 h.
Comparison period: the previous month; when the current month is incomplete, the same day range of the previous month (e.g. 1-15 Nov vs 1-15 Oct).
Sales driver factors flagged when: daily reports fall below 75% of the previous period; operating hours/day change >= 15%; rain slots/day change >= 1; absenteeism up >= 8 points; e-Docket or CCTV down >= 2 days; lorry trips change >= 20%.
Site health score (dashboard): starts at 100; permit expired -30, permit warning -10, no permit date -5; no reports -35; sales down >= 30% -20 (>= 10% -10); system down days x3 (max 15); turbid effluent >= 70% of days -10; spillway issues (max 10); absenteeism > 15% -10. Good >= 80, Watch 60-79, Risk < 60.
Permit status: Active = more than 90 days left, Warning = 90 days or less, Expired = end date passed, No data = no end date.
Document compliance (monitoring): required documents are Permit & License, LUAS approval, OMS (current if <= 365 days old) and Site report (current if <= 180 days old); EIA is optional. Score = % of required documents current.
Attendance: rate = present / (records - on leave - blank rest days). Late = clock-in after 08:30 and before 12:00 (the legacy "late" status is inconsistent so it is not used). Watchlist flags: late >= 4, absent >= 2, no clock-out >= 3, present without clock-in >= 2, more than 11 h on >= 5 days, leave >= 10 days.
Offices: HQ = headquarters, PSU = Pejabat Satelit Utara (northern), PSS = Pejabat Satelit Selatan (southern).
T],
            ['ref-docket-causes', 'Docket cancellation / correction causes', <<<'T'
Docket incident reports are classified from their text:
- Wrong customer / product selected (e.g. "TERSALAH PILIH CUSTOMER DAN PRODUK") — human data-entry error; fix with clerk training and a confirmation step.
- Data missing in Gemma system ("DATA TIADA DI DALAM SISTEM GEMMA") — system sync issue; escalate to the Gemma vendor.
- QR docket mis-scan ("TERSALAH SCAN QR DOKET") — scanner or procedure issue.
- Lorry breakdown ("LORI MENGALAMI KEROSAKAN") — legitimate cancellation; needs an SOP with photo evidence.
- Weight / load discrepancy — calibrate the weighbridge.
Gemma = the docket/sales system; e-Docket = electronic docket issuance; AVR = weighbridge reading.
T],
        ];
        foreach ($refs as [$slug, $title, $body]) $this->upsert($slug, 'general', 'reference', $title, $body, ['reference']);
        return count($refs);
    }
}

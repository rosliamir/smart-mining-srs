<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Compliance & monitoring intelligence.
 *
 *  - Document compliance: does every operating site hold the mandatory documents
 *    (Permit, LUAS approval, OMS, site report, EIA) and are they current?
 *  - Docket integrity: docket correction / cancellation reports classified by root
 *    cause, e-Docket downtime and implausible sales entries.
 *  - Agency visits: JMG / JAS / PTD / TL … mentions in F001 daily remarks.
 *  - Landowner monthly reports approval backlog.
 *  - Repository hygiene: monitoring records without a file, stale uploads.
 * Each finding becomes a prioritised action with an owner.
 */
class MonitoringInsightsController extends Controller
{
    private const REQUIRED = [
        'Permit & License Approval' => ['label' => 'Permit', 'max_age' => null, 'required' => true],
        'LUAS Approval'             => ['label' => 'LUAS',   'max_age' => null, 'required' => true],
        'OMS'                       => ['label' => 'OMS',    'max_age' => 365,  'required' => true],
        'Site Report'               => ['label' => 'Site report', 'max_age' => 180, 'required' => true],
        'EIA'                       => ['label' => 'EIA',    'max_age' => null, 'required' => false],
    ];
    private const AGENCIES = ['JMG', 'JAS', 'PTD', 'TL', 'JPS', 'PBT', 'MPKS', 'MPSJ', 'POLIS', 'PDRM', 'SPRM', 'KSSB', 'LUAS', 'DOSH', 'JKKP', 'BOMBA'];

    public function index(Request $req)
    {
        $region = $req->input('region');
        $today = now()->startOfDay();
        $since = $today->copy()->subDays(60);

        $sites = $this->operatingSites($region, $since, $today);
        $docs = $this->documentMatrix($sites, $today);
        $dockets = $this->dockets($region, $today);
        $visits = $this->visits($region, $today);
        $landowner = $this->landowner($region, $today);
        $repo = $this->repository($today);
        $actions = $this->actions($sites, $docs, $dockets, $visits, $landowner, $repo);

        $fullyDoc = count(array_filter($docs['rows'], fn ($r) => $r['score'] === 100));
        $kpis = [
            ['key' => 'doc', 'label' => 'Document compliance', 'value' => $docs['compliance'], 'unit' => '%', 'good' => 'up', 'hint' => 'Required documents present & current across operating sites'],
            ['key' => 'full', 'label' => 'Fully documented', 'value' => $fullyDoc, 'of' => count($docs['rows']), 'unit' => 'sites', 'good' => 'up', 'hint' => 'Operating sites with every required document'],
            ['key' => 'permit', 'label' => 'Expired permits', 'value' => count(array_filter($sites, fn ($s) => $s['permit'] === 'expired')), 'unit' => 'sites', 'good' => 'down', 'hint' => 'Operating sites whose permit end date has passed'],
            ['key' => 'docket', 'label' => 'Docket incidents', 'value' => $dockets['total'], 'unit' => '', 'good' => 'down', 'hint' => 'Docket corrections / cancellations reported'],
            ['key' => 'edocket', 'label' => 'e-Docket down days', 'value' => $dockets['edocket_down_days'], 'unit' => 'days', 'good' => 'down', 'hint' => 'Site-days with e-Docket "rosak/off" in the last 30 days'],
            ['key' => 'anomaly', 'label' => 'Suspicious sales', 'value' => count($dockets['anomalies']), 'unit' => '', 'good' => 'down', 'hint' => 'Sales entries > 100 MT per lorry trip'],
            ['key' => 'visits', 'label' => 'Agency visits (90d)', 'value' => $visits['total'], 'unit' => '', 'good' => null, 'hint' => 'Agency mentions in F001 daily remarks, last 90 days'],
            ['key' => 'landowner', 'label' => 'Landowner backlog', 'value' => $landowner['pending'], 'unit' => 'reports', 'good' => 'down', 'hint' => 'Landowner monthly reports not yet approved'],
        ];

        return [
            'as_of' => $today->toDateString(), 'region' => $region,
            'kpis' => $kpis,
            'summary' => $this->summary($docs, $dockets, $visits, $landowner, $actions, $sites),
            'documents' => $docs, 'dockets' => $dockets, 'visits' => $visits,
            'landowner' => $landowner, 'repository' => $repo, 'actions' => $actions,
        ];
    }

    /* ── sites operating in the last 60 days ──────────────────────────── */
    private function operatingSites(?string $region, Carbon $since, Carbon $today): array
    {
        $bind = [$since->toDateString()];
        $rq = $region ? ' AND r.name = ?' : '';
        if ($region) $bind[] = $region;
        $rows = DB::select("SELECT s.id, s.site_code code, s.name, r.name region, s.permit_date_end, s.documents,
              max(dr.date) last_report, count(dr.id) reports
            FROM sites s LEFT JOIN regions r ON r.id=s.region_id
            JOIN weekly_reports wr ON wr.site_id=s.id JOIN daily_reports dr ON dr.weekly_report_id=wr.id AND dr.date >= ?
            WHERE true $rq GROUP BY s.id, r.name ORDER BY s.site_code", $bind);
        $out = [];
        foreach ($rows as $s) {
            $days = $s->permit_date_end ? (int) $today->diffInDays(Carbon::parse($s->permit_date_end), false) : null;
            $out[$s->id] = ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'region' => $s->region,
                'days_left' => $days, 'permit' => $days === null ? 'unknown' : ($days < 0 ? 'expired' : ($days <= 90 ? 'warning' : 'active')),
                'documents' => json_decode($s->documents ?? '[]', true) ?: [], 'last_report' => $s->last_report];
        }
        return $out;
    }

    /* ── document compliance matrix ───────────────────────────────────── */
    private function documentMatrix(array $sites, Carbon $today): array
    {
        $rows = []; $ok = 0; $need = 0;
        $byCat = array_fill_keys(array_keys(self::REQUIRED), ['ok' => 0, 'stale' => 0, 'missing' => 0]);
        foreach ($sites as $s) {
            $cells = []; $sOk = 0; $sNeed = 0;
            foreach (self::REQUIRED as $cat => $rule) {
                $docs = array_values(array_filter($s['documents'], fn ($d) => ($d['category'] ?? '') === $cat));
                usort($docs, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
                $latest = $docs[0] ?? null;
                $age = $latest && !empty($latest['date']) ? (int) Carbon::parse($latest['date'])->diffInDays($today) : null;
                if (!$latest) $state = 'missing';
                elseif ($cat === 'Permit & License Approval' && $s['permit'] === 'expired') $state = 'stale';
                elseif ($rule['max_age'] && $age !== null && $age > $rule['max_age']) $state = 'stale';
                else $state = 'ok';
                $byCat[$cat][$state]++;
                if ($rule['required']) { $sNeed++; $need++; if ($state === 'ok') { $sOk++; $ok++; } }
                $cells[$cat] = ['state' => $state, 'count' => count($docs), 'latest' => $latest['date'] ?? null, 'age' => $age,
                    'url' => $latest['url'] ?? null, 'name' => $latest['name'] ?? null, 'required' => $rule['required']];
            }
            $rows[] = ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'region' => $s['region'], 'permit' => $s['permit'],
                'days_left' => $s['days_left'], 'cells' => $cells, 'score' => $sNeed ? (int) round($sOk / $sNeed * 100) : 0];
        }
        usort($rows, fn ($a, $b) => $a['score'] <=> $b['score'] ?: strcmp($a['code'], $b['code']));
        return ['categories' => array_map(fn ($c, $r) => ['key' => $c, 'label' => $r['label'], 'required' => $r['required'], 'max_age' => $r['max_age']] + $byCat[$c],
                    array_keys(self::REQUIRED), self::REQUIRED),
            'rows' => $rows, 'compliance' => $need ? round($ok / $need * 100, 1) : null];
    }

    /* ── docket integrity ─────────────────────────────────────────────── */
    private function cause(string $t): array
    {
        $t = mb_strtolower($t);
        return match (true) {
            str_contains($t, 'customer') || str_contains($t, 'produk') => ['wrong_entry', 'Wrong customer / product selected'],
            str_contains($t, 'qr') || str_contains($t, 'scan') => ['scan', 'QR docket mis-scan'],
            str_contains($t, 'gemma') || str_contains($t, 'sistem') => ['system', 'Data missing in Gemma system'],
            str_contains($t, 'lori') && (str_contains($t, 'rosak') || str_contains($t, 'kerosakan')) => ['truck', 'Lorry breakdown'],
            str_contains($t, 'berat') || str_contains($t, 'muatan') => ['weight', 'Weight / load discrepancy'],
            default => ['other', 'Other'],
        };
    }

    private function dockets(?string $region, Carbon $today): array
    {
        $q = DB::table('reports as rp')->leftJoin('sites as s', 's.id', '=', 'rp.site_id')->leftJoin('regions as r', 'r.id', '=', 's.region_id')
            ->select('rp.id', 'rp.name', 'rp.date', 'rp.type', 'rp.severity', 'rp.status', 'rp.issue', 'rp.action', 's.id as site_id', 's.site_code as code');
        if ($region) $q->where('r.name', $region);
        $items = []; $causes = []; $bySite = [];
        foreach ($q->orderByDesc('rp.date')->get() as $r) {
            $isDocket = in_array($r->type, ['docket_correction', 'docket_cancelation']) || str_contains(mb_strtolower($r->name ?? ''), 'doket');
            if (!$isDocket) continue;
            [$ck, $cl] = $this->cause(($r->issue ?? '') . ' ' . ($r->name ?? ''));
            $causes[$ck] ??= ['key' => $ck, 'label' => $cl, 'count' => 0];
            $causes[$ck]['count']++;
            if ($r->code) { $bySite[$r->code] ??= ['code' => $r->code, 'site_id' => $r->site_id, 'count' => 0]; $bySite[$r->code]['count']++; }
            // many legacy docket reports have no date field — recover it from "… PADA 10.2.2026" in the text
            $date = $r->date;
            if (!$date && preg_match('/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](20\d{2})\b/', ($r->action ?? '') . ' ' . ($r->issue ?? ''), $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3]))
                $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            $items[] = ['id' => $r->id, 'date' => $date, 'date_inferred' => !$r->date && $date, 'code' => $r->code, 'site_id' => $r->site_id,
                'type' => $r->type === 'docket_cancelation' ? 'Cancellation' : 'Correction', 'severity' => $r->severity, 'status' => $r->status,
                'cause' => $cl, 'cause_key' => $ck, 'issue' => trim(preg_replace('/\s+/', ' ', $r->issue ?? '')), 'action' => trim(preg_replace('/\s+/', ' ', $r->action ?? ''))];
        }
        usort($items, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        usort($causes, fn ($a, $b) => $b['count'] <=> $a['count']);
        usort($bySite, fn ($a, $b) => $b['count'] <=> $a['count']);

        $bind = [$today->copy()->subDays(30)->toDateString()];
        $rq = $region ? ' AND s.region_id=(SELECT id FROM regions WHERE name=?)' : '';
        if ($region) $bind[] = $region;
        $down = DB::select("SELECT s.id site_id, s.site_code code, count(*) FILTER (WHERE ss.e_docket_status='rosak_off') edocket,
              count(*) FILTER (WHERE ss.cty_gemma_status='rosak_off') gemma,
              count(*) FILTER (WHERE ss.cctv_luar_status='rosak_off' OR ss.cctv_dalam_kabin_status='rosak_off') cctv
            FROM daily_system_statuses ss JOIN daily_reports dr ON dr.id=ss.daily_report_id
            JOIN weekly_reports wr ON wr.id=dr.weekly_report_id JOIN sites s ON s.id=wr.site_id
            WHERE dr.date >= ? $rq GROUP BY s.id HAVING count(*) FILTER (WHERE ss.e_docket_status='rosak_off' OR ss.cty_gemma_status='rosak_off'
              OR ss.cctv_luar_status='rosak_off' OR ss.cctv_dalam_kabin_status='rosak_off') > 0 ORDER BY 3 DESC, 5 DESC", $bind);

        $abind = [];
        $arq = $region ? ' AND s.region_id=(SELECT id FROM regions WHERE name=?)' : '';
        if ($region) $abind[] = $region;
        $anom = DB::select("SELECT dsr.id, s.id site_id, s.site_code code, dr.date::text date, dsr.jualan sales, dsr.lori_trip trips,
              round(dsr.jualan/NULLIF(dsr.lori_trip,0),1) per_trip
            FROM daily_sales_records dsr JOIN daily_reports dr ON dr.id=dsr.daily_report_id
            JOIN weekly_reports wr ON wr.id=dr.weekly_report_id JOIN sites s ON s.id=wr.site_id
            WHERE (" . InsightsController::ANOMALY . ")$arq ORDER BY dsr.jualan DESC", $abind);

        return ['total' => count($items), 'items' => $items, 'causes' => array_values($causes), 'by_site' => array_values($bySite),
            'downtime' => array_map(fn ($r) => (array) $r, $down),
            'edocket_down_days' => array_sum(array_map(fn ($r) => $r->edocket + $r->gemma, $down)),
            'anomalies' => array_map(fn ($r) => (array) $r, $anom)];
    }

    /* ── agency visits from F001 daily remarks ────────────────────────── */
    private function visits(?string $region, Carbon $today): array
    {
        $bind = [$today->copy()->subDays(90)->toDateString()];
        $rq = $region ? ' AND s.region_id=(SELECT id FROM regions WHERE name=?)' : '';
        if ($region) $bind[] = $region;
        $rows = DB::select("SELECT nc.tarikh::text date, nc.jabatan, nc.ulasan, s.id site_id, s.site_code code
            FROM non_compliances nc JOIN weekly_reports wr ON wr.id=nc.weekly_report_id JOIN sites s ON s.id=wr.site_id
            WHERE nc.tarikh >= ? $rq ORDER BY nc.tarikh DESC", $bind);
        $items = []; $byAgency = []; $themes = ['rain' => 0, 'system' => 0, 'road' => 0, 'sales_ok' => 0];
        foreach ($rows as $r) {
            $text = trim(($r->jabatan ? $r->jabatan . ' ' : '') . ($r->ulasan ?? ''));
            $up = mb_strtoupper($text);
            $found = array_values(array_filter(self::AGENCIES, fn ($a) => preg_match('/\b' . $a . '\b/u', $up)));
            if (preg_match('/hujan/i', $text)) $themes['rain']++;
            if (preg_match('/cty|cctv|cpu|sistem|gemma|rosak/i', $text)) $themes['system']++;
            if (preg_match('/jalan|laluan/i', $text)) $themes['road']++;
            if (preg_match('/lancar|baik/i', $text)) $themes['sales_ok']++;
            foreach ($found as $a) $byAgency[$a] = ($byAgency[$a] ?? 0) + 1;
            $items[] = ['date' => $r->date, 'code' => $r->code, 'site_id' => $r->site_id, 'text' => preg_replace('/\s+/', ' ', $text),
                'agencies' => $found, 'visit' => (bool) ($found && preg_match('/lawatan|datang|hadir|pemeriksaan|audit|siasat/i', $text))];
        }
        arsort($byAgency);
        $visitItems = array_values(array_filter($items, fn ($i) => $i['agencies']));
        return ['total' => count($visitItems), 'notes' => count($items), 'by_agency' => $byAgency, 'themes' => $themes,
            'items' => array_slice($items, 0, 40), 'visits' => array_slice($visitItems, 0, 20)];
    }

    /* ── landowner monthly reports ────────────────────────────────────── */
    private function landowner(?string $region, Carbon $today): array
    {
        $q = DB::table('landowner_monthly_reports as l')->join('sites as s', 's.id', '=', 'l.site_id')->leftJoin('regions as r', 'r.id', '=', 's.region_id');
        if ($region) $q->where('r.name', $region);
        $rows = $q->select('l.status', 'l.date', 's.id as site_id', 's.site_code as code')->get();
        $pending = $rows->filter(fn ($r) => $r->status !== 'approved');
        $bySite = $pending->groupBy('code')->map(fn ($g, $code) => ['code' => $code, 'site_id' => $g->first()->site_id, 'count' => $g->count(),
            'oldest' => $g->min('date'), 'age_days' => (int) Carbon::parse($g->min('date'))->diffInDays($today)])->sortByDesc('age_days')->values();
        return ['total' => $rows->count(), 'pending' => $pending->count(),
            'by_status' => $rows->groupBy('status')->map->count(), 'oldest' => $pending->min('date'),
            'over_60' => $pending->filter(fn ($r) => Carbon::parse($r->date)->diffInDays($today) > 60)->count(), 'by_site' => $bySite];
    }

    /* ── repository hygiene ───────────────────────────────────────────── */
    private function repository(Carbon $today): array
    {
        $rows = DB::table('monitoring_reports')->get(['id', 'name', 'type', 'published_at', 'files']);
        $noFile = $rows->filter(fn ($r) => !json_decode($r->files ?? '[]', true))->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'date' => substr($r->published_at, 0, 10)])->values();
        $byType = $rows->groupBy('type')->map(fn ($g, $t) => ['type' => $t, 'count' => $g->count(), 'latest' => substr($g->max('published_at'), 0, 10),
            'age_days' => (int) Carbon::parse($g->max('published_at'))->diffInDays($today)])->values();
        $dupes = $rows->groupBy(fn ($r) => mb_strtolower(trim(preg_replace('/\b(latest|final|v\d+)\b/i', '', $r->name))))->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->pluck('name')->all())->values();
        return ['total' => $rows->count(), 'no_file' => $noFile, 'by_type' => $byType, 'duplicates' => $dupes];
    }

    /* ── actions ──────────────────────────────────────────────────────── */
    private function actions(array $sites, array $docs, array $dk, array $visits, array $lo, array $repo): array
    {
        $A = [];
        $add = function ($sev, $cat, $title, $why, $action, $owner, $link = null, $site = null) use (&$A) {
            $A[] = compact('sev', 'cat', 'title', 'why', 'action', 'owner', 'link', 'site') + ['id' => 'm' . md5($cat . $title)];
        };
        $list = fn ($a, $n = 8) => implode(', ', array_slice($a, 0, $n)) . (count($a) > $n ? ' …(+' . (count($a) - $n) . ')' : '');

        if ($dk['anomalies']) $add('critical', 'Data integrity', count($dk['anomalies']) . ' implausible sales entries',
            'Over 100 MT per lorry trip: ' . $list(array_map(fn ($a) => "{$a['code']} {$a['date']} (" . number_format($a['sales']) . " MT/{$a['trips']} trips)", $dk['anomalies']), 3),
            'Audit and correct these F001 entries against weighbridge AVR & e-Docket', 'Executive');

        $expired = array_filter($sites, fn ($s) => $s['permit'] === 'expired');
        if ($expired) $add('critical', 'Permit', count($expired) . ' operating site(s) with EXPIRED permit',
            'Reported activity in the last 60 days: ' . $list(array_column($expired, 'code')),
            'Stop operations or upload the renewed permit immediately', 'Manager', '/map');

        foreach (['LUAS Approval' => 'high', 'OMS' => 'high', 'Site Report' => 'medium'] as $cat => $sev) {
            $miss = array_column(array_filter($docs['rows'], fn ($r) => $r['cells'][$cat]['state'] === 'missing'), 'code');
            $stale = array_column(array_filter($docs['rows'], fn ($r) => $r['cells'][$cat]['state'] === 'stale'), 'code');
            $label = self::REQUIRED[$cat]['label'];
            if ($miss) $add($sev, 'Documents', count($miss) . " operating site(s) missing {$label}", $list($miss),
                "Obtain and upload the {$label} document for each site", 'Site officer');
            if ($stale && $cat !== 'Permit & License Approval') $add('medium', 'Documents', count($stale) . " site(s) with outdated {$label}",
                "Older than " . self::REQUIRED[$cat]['max_age'] . " days: " . $list($stale), "Upload a current {$label}", 'Site officer');
        }

        foreach (array_slice(array_filter($dk['downtime'], fn ($d) => $d['edocket'] + $d['gemma'] >= 3), 0, 5) as $d)
            $add('high', 'Systems', "{$d['code']}: e-Docket/Gemma down " . ($d['edocket'] + $d['gemma']) . " day(s) in 30 days",
                "Sales during downtime are issued without e-Docket verification (CCTV down {$d['cctv']}d)",
                'Dispatch technician; reconcile manual dockets against weighbridge AVR', 'IT / Technician', "/site/{$d['site_id']}", $d['site_id']);

        if ($dk['causes']) {
            $top = $dk['causes'][0];
            $fix = ['wrong_entry' => 'Retrain weighbridge clerks; add customer/product confirmation step before printing',
                'scan' => 'Check QR scanners; retrain on scan procedure', 'system' => 'Escalate Gemma sync failures to the vendor',
                'truck' => 'Define a breakdown-cancellation SOP with photo evidence', 'weight' => 'Calibrate weighbridge; investigate load variance',
                'other' => 'Review docket incident reports with site officers'][$top['key']];
            $add('medium', 'Dockets', "Top docket issue: {$top['label']} ({$top['count']} of {$dk['total']})",
                'Repeat sites: ' . $list(array_map(fn ($s) => "{$s['code']} ×{$s['count']}", array_filter($dk['by_site'], fn ($s) => $s['count'] >= 2)), 5), $fix, 'Manager');
        }

        if ($lo['over_60']) $add('medium', 'Landowner', "{$lo['over_60']} landowner report(s) unapproved > 60 days",
            "{$lo['pending']} pending in total; oldest from {$lo['oldest']}", 'Review and approve landowner monthly reports — royalty payments may be delayed', 'Manager');

        $recent = array_filter($visits['visits'], fn ($v) => $v['visit']);
        if ($recent) $add('low', 'Agency', count($recent) . ' agency visit(s) recorded in the last 90 days',
            $list(array_map(fn ($v) => "{$v['code']} " . implode('/', $v['agencies']) . " ({$v['date']})", $recent), 4),
            'Make sure visit findings are filed and follow-ups tracked', 'Executive');

        if ($repo['no_file']->count()) $add('low', 'Repository', $repo['no_file']->count() . ' monitoring record(s) without an attached file',
            $list($repo['no_file']->pluck('name')->all(), 3), 'Re-upload the missing files or remove the empty records', 'Executive');
        if ($repo['duplicates']->count()) $add('low', 'Repository', $repo['duplicates']->count() . ' possible duplicate document set(s)',
            $list($repo['duplicates']->map(fn ($d) => $d[0] . ' ×' . count($d))->all(), 3), 'Keep the final version and archive the rest', 'Executive');

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($A, fn ($a, $b) => $rank[$a['sev']] <=> $rank[$b['sev']]);
        return $A;
    }

    private function summary(array $docs, array $dk, array $v, array $lo, array $actions, array $sites): array
    {
        $what = [
            sprintf('%d operating sites; document compliance %s%%', count($sites), $docs['compliance'] ?? 0),
            sprintf('%d docket incidents on record, %d e-Docket/Gemma downtime site-days in the last 30 days', $dk['total'], $dk['edocket_down_days']),
            sprintf('%d landowner reports awaiting approval; %d agency mentions in daily remarks (90 days)', $lo['pending'], $v['total']),
        ];
        $why = [];
        $worst = collect($docs['categories'])->filter(fn ($c) => $c['required'])->sortByDesc(fn ($c) => $c['missing'] + $c['stale'])->first();
        if ($worst) $why[] = "Biggest document gap: {$worst['label']} ({$worst['missing']} missing, {$worst['stale']} outdated)";
        if ($dk['causes']) $why[] = "Most docket incidents are caused by: " . mb_strtolower($dk['causes'][0]['label']) . " ({$dk['causes'][0]['count']} of {$dk['total']}) — a process/training issue, not fraud";
        if ($dk['anomalies']) $why[] = count($dk['anomalies']) . ' sales entries look like typing errors (> 100 MT per trip) and distort reported totals';
        if ($v['themes']['rain']) $why[] = "Rain is mentioned in {$v['themes']['rain']} of {$v['notes']} daily remarks — the main operational disruption noted by site officers";
        $sev = array_count_values(array_column($actions, 'sev'));
        return ['what' => $what, 'why' => $why, 'do' => array_map(fn ($a) => $a['action'] . ' — ' . $a['title'], array_slice($actions, 0, 3)),
            'counts' => ['critical' => $sev['critical'] ?? 0, 'high' => $sev['high'] ?? 0, 'medium' => $sev['medium'] ?? 0, 'low' => $sev['low'] ?? 0],
            'tone' => ($sev['critical'] ?? 0) ? 'bad' : (($sev['high'] ?? 0) ? 'neutral' : 'good')];
    }
}

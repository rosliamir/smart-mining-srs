<?php

namespace App\Http\Controllers;

use App\Models\{Site, Region, Contractor, Employee, Camera, Report, WeeklyReport, DailyReport, MonitoringReport, User};
use App\Services\F001Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash, DB};
use Spatie\Permission\Models\Role;

class ApiController extends Controller
{
    /** POST /api/login  { email|identifier, password } -> { token, user } */
    public function login(Request $req)
    {
        $id = $req->input('identifier', $req->input('email'));
        // staff sign in with their staff code (KS0197) in any letter case, or their e-mail
        $user = User::whereRaw('lower(email) = lower(?)', [$id])->orWhereRaw('lower(username) = lower(?)', [$id])->first();
        if (! $user || ! Hash::check($req->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }
        if ($user->blocked) return response()->json(['message' => 'User blocked'], 403);
        return [
            'token' => $user->createToken('api')->plainTextToken,
            'user'  => $this->userPayload($user),
        ];
    }

    public function me(Request $req) { return $this->userPayload($req->user()); }

    public function logout(Request $req)
    {
        $req->user()->currentAccessToken()->delete();
        return ['ok' => true];
    }

    private function userPayload(User $u): array
    {
        return [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email, 'name' => $u->name, 'employee_code' => $u->employee_id,
            'position' => $u->position, 'roles' => $u->getRoleNames(),
            // staff-only accounts use the self-service portal; everyone else also gets the admin console
            'ess_only' => $u->hasRole('staff') && !$u->hasAnyRole(['super_admin', 'manager', 'executive', 'site_officer', 'po', 'authenticated']),
            'permissions' => $u->getAllPermissions()->pluck('name'),
        ];
    }

    // ---- read endpoints (paginated) ----
    public function sites() { return Site::with(['region','contractor'])->paginate(request('per_page', 100)); }
    public function site($id) { return Site::with(['region','contractor','cameras','weeklyReports'])->findOrFail($id); }
    /** GET /api/sites/{id}/media — real documents (by category) + gallery images. */
    public function siteMedia($id)
    {
        $site = Site::findOrFail($id);
        $docs = $site->documents ?? [];
        $catOrder = ['LUAS Approval', 'Permit & License Approval', 'Site Report', 'OMS', 'EIA', 'HSE'];
        $byCat = collect($catOrder)->mapWithKeys(fn ($c) => [$c => collect($docs)->where('category', $c)->count()]);
        $gallery = DB::table('site_galleries')->where('site_id', $site->id)->get()
            ->flatMap(fn ($g) => collect(json_decode($g->media ?? '[]', true))
                ->map(fn ($m) => ['name' => $m['name'] ?? null, 'url' => $m['url'] ?? null,
                    'size' => $m['size'] ?? null, 'date' => substr($m['createdAt'] ?? '', 0, 10), 'gallery' => $g->name]))
            ->values();
        return [
            'files' => $docs,
            'file_categories' => $byCat,
            'total_files' => count($docs),
            'gallery' => $gallery,
            'gallery_count' => $gallery->count(),
        ];
    }

    public function regions() { return Region::withCount('sites')->get(); }
    public function contractors() { return Contractor::all(); }
    public function employees() { return Employee::paginate(request('per_page', 200)); }
    public function cameras() { return Camera::with('site:id,name,site_code')->get(); }
    public function reports() { return Report::with('site:id,name,site_code')->latest()->paginate(request('per_page', 100)); }
    public function monitoringReports() { return MonitoringReport::latest()->get(); }
    public function users() { return \App\Models\User::with("roles:id,name")->paginate(request("per_page", 200)); }
    /** GET /api/attendances?date=YYYY-MM-DD — real daily attendance with clock times. */
    public function attendances(Request $req)
    {
        $date = $req->input('date', \App\Models\Attendance::max('date'));
        $rows = \App\Models\Attendance::with('employee:id,name,employee_id,job_level,hq_region')
            ->whereDate('date', $date)
            ->get()
            ->filter(fn ($a) => $a->employee)
            ->map(fn ($a) => [
                'id' => $a->id,
                'code' => $a->employee->employee_id,
                'name' => $a->employee->name,
                'role' => strtoupper($a->employee->job_level ?? ''),
                'office' => $this->office($a->employee->hq_region),
                'office_code' => $this->officeCode($a->employee->hq_region),
                'check_in' => $a->timeclock_in ? substr($a->timeclock_in, 0, 5) : null,
                'check_out' => $a->timeclock_out ? substr($a->timeclock_out, 0, 5) : null,
                'duration' => $this->duration($a->timeclock_in, $a->timeclock_out),
                'status' => $a->status,
            ])->values();
        return [
            'date' => $date,
            'summary' => [
                'total' => \App\Models\Attendance::distinct('employee_id')->count('employee_id'),
                'present' => $rows->where('status', 'present')->count(),
                'late' => $rows->where('status', 'late')->count(),
                'absent' => $rows->where('status', 'absent')->count(),
                'on_leave' => $rows->where('status', 'on_leave')->count(),
            ],
            'rows' => $rows,
        ];
    }

    /** GET /api/attendances/monthly?code=KS0002&month=YYYY-MM — per-day for the detail modal. */
    public function attendanceMonthly(Request $req)
    {
        $code = $req->input('code');
        $month = $req->input('month', date('Y-m'));
        $emp = \App\Models\Employee::where('employee_id', $code)->first();
        if (! $emp) return ['days' => [], 'employee' => null];
        [$y, $m] = explode('-', $month);
        $rows = \App\Models\Attendance::where('employee_id', $emp->id)
            ->whereYear('date', $y)->whereMonth('date', $m)->get();
        $days = $rows->mapWithKeys(fn ($a) => [ (int) date('j', strtotime($a->date)) => [
            'status' => $a->status,
            'check_in' => $a->timeclock_in ? substr($a->timeclock_in, 0, 5) : null,
        ]]);
        return [
            'employee' => ['code' => $emp->employee_id, 'name' => $emp->name, 'workdays' => $emp->workdays,
                'role' => strtoupper($emp->job_level ?? ''), 'office' => $this->office($emp->hq_region)],
            'days' => $days,
        ];
    }

    /** GET /api/attendances/grid?month=YYYY-MM — all staff × per-day status for the monthly grid. */
    public function attendanceGrid(Request $req)
    {
        $month = $req->input('month', date('Y-m'));
        [$y, $m] = explode('-', $month);
        $atts = \App\Models\Attendance::with('employee:id,name,employee_id,job_level,hq_region')
            ->whereYear('date', $y)->whereMonth('date', $m)->get()->filter(fn ($a) => $a->employee && $a->employee->employee_id);
        $byEmp = $atts->groupBy(fn ($a) => $a->employee->employee_id);
        $staff = $byEmp->map(function ($rows) {
            $e = $rows->first()->employee;
            return [
                'code' => $e->employee_id, 'name' => $e->name,
                'role' => strtoupper($e->job_level ?? ''), 'office_code' => $this->officeCode($e->hq_region),
                'days' => $rows->mapWithKeys(fn ($a) => [(int) date('j', strtotime($a->date)) => $a->status])->all(),
            ];
        })->values();
        return ['month' => $month, 'staff' => $staff];
    }

    private function office(?string $r): string
    {
        return match ($r) { 'northern' => 'Pejabat Satelit Utara', 'southern' => 'Pejabat Satelit Selatan', default => 'HQ' };
    }
    private function officeCode(?string $r): string
    {
        return match ($r) { 'northern' => 'PSU', 'southern' => 'PSS', default => 'HQ' };
    }
    private function duration(?string $in, ?string $out): ?string
    {
        if (! $in || ! $out) return null;
        $mins = (strtotime($out) - strtotime($in)) / 60;
        if ($mins <= 0) return null;
        return floor($mins / 60) . 'H ' . ((int) $mins % 60) . 'M';
    }
    public function roles() { return Role::with('permissions:id,name')->get(); }

    public function weeklyReports(Request $req)
    {
        $q = WeeklyReport::with(['site:id,name,site_code','contractor:id,name','preparedBy:id,username']);
        if ($req->site) $q->where('site_id', $req->site);
        if ($req->status) $q->where('status', $req->status);
        return $q->latest()->paginate($req->input('per_page', 50));
    }

    public function weeklyReport($id)
    {
        return WeeklyReport::with([
            'site','contractor','preparedBy',
            'dailyReports.systemStatus', 'dailyReports.environmentStatus',
            'assignments.employee', 'salesProducts', 'notes', 'nonCompliances',
            'activities', 'equipmentEntries.itemType',
        ])->findOrFail($id);
    }

    /** POST /api/weekly-reports/{id}/transition { to } — enforces the F001 state machine. */
    public function transition(Request $req, $id, F001Workflow $flow)
    {
        $req->validate(['to' => 'required|string']);
        $report = WeeklyReport::findOrFail($id);
        $updated = $flow->transition($report, $req->to, $req->user());
        return ['status' => $updated->status, 'report' => $updated->load('activities')];
    }

    /** POST /api/sync — re-scrape the latest data from the live system (super_admin only). */
    public function sync(Request $req)
    {
        if (! $req->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Only Super Admin can sync data.'], 403);
        }
        set_time_limit(600); // the full re-scrape can take a couple of minutes
        $script = base_path('database/sync_latest.py');
        $out = [];
        $code = 0;
        exec('/usr/bin/python3 ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $last = trim(end($out) ?: '');
        $json = json_decode($last, true);
        if (! is_array($json)) {
            return response()->json(['ok' => false, 'error' => 'Sync failed', 'raw' => implode("\n", array_slice($out, -5))], 500);
        }
        return $json;
    }

    /** Real sales analytics from daily_sales_records (for the dashboard charts). */
    public function sales(Request $req)
    {
        $year = $req->input('year', 2026);
        $base = "FROM daily_sales_records dsr JOIN daily_reports dr ON dr.id=dsr.daily_report_id "
              . "JOIN weekly_reports wr ON wr.id=dr.weekly_report_id JOIN sites s ON s.id=wr.site_id "
              . "LEFT JOIN regions r ON r.id=s.region_id "
              . "WHERE extract(year from dr.date)=? AND dsr.jualan IS NOT NULL";

        $monthly = DB::select("SELECT extract(month from dr.date)::int m, round(sum(dsr.jualan)::numeric,2) v $base GROUP BY 1 ORDER BY 1", [$year]);
        $months = array_fill(1, 12, 0.0);
        foreach ($monthly as $r) $months[$r->m] = (float) $r->v;

        $region = DB::select("SELECT COALESCE(r.name,'Unassigned') name, round(sum(dsr.jualan)::numeric,2) v $base GROUP BY 1 ORDER BY 2 DESC", [$year]);

        // per-site: total + monthly, for the site selector chart
        $sites = DB::select(
            "SELECT s.id, s.name, s.site_code, COALESCE(r.name,'Unassigned') region,
                    extract(month from dr.date)::int m, round(sum(dsr.jualan)::numeric,2) v
             $base GROUP BY s.id, s.name, s.site_code, r.name, m ORDER BY s.name", [$year]);
        $siteMap = [];
        foreach ($sites as $row) {
            $siteMap[$row->id] ??= ['id' => $row->id, 'name' => $row->name, 'site_code' => $row->site_code,
                'region' => $row->region, 'total' => 0.0, 'months' => array_fill(1, 12, 0.0)];
            $siteMap[$row->id]['months'][$row->m] = (float) $row->v;
            $siteMap[$row->id]['total'] += (float) $row->v;
        }
        $siteList = array_values($siteMap);
        usort($siteList, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'year' => (int) $year,
            'total' => array_sum($months),
            'monthly' => array_map(fn ($m) => $months[$m], range(1, 12)),
            'by_region' => collect($region)->mapWithKeys(fn ($r) => [$r->name => (float) $r->v]),
            'sites' => array_map(fn ($s) => [...$s, 'months' => array_values($s['months'])], $siteList),
        ];
    }

    /** GET /api/sales/daily?site=ID&month=M&year=Y — daily sales for a site in a month. */
    public function salesDaily(Request $req)
    {
        $year = $req->input('year', 2026);
        $month = $req->input('month');
        $site = $req->input('site');
        $rows = DB::select(
            "SELECT extract(day from dr.date)::int d, round(sum(dsr.jualan)::numeric,2) v
             FROM daily_sales_records dsr JOIN daily_reports dr ON dr.id=dsr.daily_report_id
             JOIN weekly_reports wr ON wr.id=dr.weekly_report_id
             WHERE extract(year from dr.date)=? AND extract(month from dr.date)=? "
             . ($site ? "AND wr.site_id=? " : "")
             . "AND dsr.jualan IS NOT NULL GROUP BY 1 ORDER BY 1",
            $site ? [$year, $month, $site] : [$year, $month]
        );
        $days = array_fill(1, 31, 0.0);
        foreach ($rows as $r) $days[$r->d] = (float) $r->v;
        return ['year' => (int) $year, 'month' => (int) $month,
            'total' => array_sum($days), 'daily' => array_values($days)];
    }

    /** Dashboard aggregates. */
    public function dashboard()
    {
        return [
            'active_sites' => Site::where('is_activated', true)->count(),
            'sites_total'  => Site::count(),
            'employees'    => Employee::count(),
            'weekly_reports' => WeeklyReport::count(),
            'open_reports' => Report::count(),
            'weekly_by_status' => WeeklyReport::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'sites_by_region'  => Site::selectRaw('region_id, count(*) c')->groupBy('region_id')->with('region')->get()
                ->mapWithKeys(fn ($r) => [optional($r->region)->name ?? 'Unassigned' => $r->c]),
            'active_by_region' => Site::where('is_activated', true)->selectRaw('region_id, count(*) c')->groupBy('region_id')->with('region')->get()
                ->mapWithKeys(fn ($r) => [optional($r->region)->name ?? 'Unassigned' => $r->c]),
            'sites_by_category' => Site::selectRaw("COALESCE(type,'Uncategorised') t, count(*) c")->where('is_activated', true)->groupBy('t')->pluck('c', 't'),
        ];
    }

    /** GET /api/map — every site with computed permit status + counts for the map page. */
    public function map()
    {
        $today = now()->startOfDay();
        $cams = DB::table('cameras')->whereNotNull('site_id')->selectRaw('site_id, count(*) c')->groupBy('site_id')->pluck('c', 'site_id');
        $polys = DB::table('site_polygons')->selectRaw('site_id, count(*) c')->groupBy('site_id')->pluck('c', 'site_id');
        $shots = DB::table('site_standoffs')->selectRaw('site_id, count(*) c')->groupBy('site_id')->pluck('c', 'site_id');
        $latest = DB::table('weekly_reports')->whereNotNull('site_id')
            ->selectRaw('DISTINCT ON (site_id) site_id, status, start_effective_date, end_effective_date')
            ->orderBy('site_id')->orderByDesc('start_effective_date')->get()->keyBy('site_id');
        $thumbs = DB::table('site_galleries')->get(['site_id', 'media'])->groupBy('site_id')->map(function ($g) {
            foreach ($g as $row) {
                foreach (array_reverse(json_decode($row->media ?? '[]', true) ?: []) as $m) {
                    $u = $m['formats']['small']['url'] ?? $m['formats']['thumbnail']['url'] ?? $m['url'] ?? null;
                    if ($u && str_starts_with($m['mime'] ?? 'image', 'image')) return $u;
                }
            }
            return null;
        });

        return Site::with(['region:id,name', 'contractor:id,name'])->orderBy('site_code')->get()->map(function ($s) use ($today, $cams, $polys, $shots, $latest, $thumbs) {
            $days = $s->permit_date_end ? (int) $today->diffInDays($s->permit_date_end, false) : null;
            $status = $days === null ? 'unknown' : ($days < 0 ? 'expired' : ($days <= 90 ? 'warning' : 'active'));
            $wr = $latest[$s->id] ?? null;
            return [
                'id' => $s->id, 'site_code' => $s->site_code, 'name' => $s->name,
                'lat' => $s->lat !== null ? (float) $s->lat : null, 'lng' => $s->lng !== null ? (float) $s->lng : null,
                'region' => $s->region?->name, 'contractor' => $s->contractor?->name,
                'type' => $s->type, 'product' => $s->product, 'is_activated' => (bool) $s->is_activated,
                'total_acreage' => $s->total_acreage !== null ? (float) $s->total_acreage : null,
                'permit_date' => $s->permit_date?->toDateString() ?? $s->permit_date,
                'permit_date_end' => $s->permit_date_end?->toDateString() ?? $s->permit_date_end,
                'luas_permit_date' => $s->luas_permit_date?->toDateString() ?? $s->luas_permit_date,
                'days_left' => $days, 'status' => $status,
                'camera_count' => (int) ($cams[$s->id] ?? 0), 'polygon_count' => (int) ($polys[$s->id] ?? 0),
                'photo_points' => (int) ($shots[$s->id] ?? 0), 'thumb' => $thumbs[$s->id] ?? null,
                'latest_f001' => $wr ? ['status' => $wr->status, 'from' => $wr->start_effective_date, 'to' => $wr->end_effective_date] : null,
            ];
        });
    }

    /** GET /api/sites/{id}/geo — boundary polygons + drone photo points for one site. */
    public function siteGeo($id)
    {
        $polygons = DB::table('site_polygons')->where('site_id', $id)->orderByDesc('date')->get(['id', 'name', 'date', 'features'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'date' => $p->date, 'geojson' => json_decode($p->features ?? 'null', true)]);
        $points = DB::table('site_standoffs')->where('site_id', $id)->whereNotNull('lat')->get(['id', 'name', 'lat', 'lng', 'media'])
            ->map(function ($p) {
                $m = json_decode($p->media ?? 'null', true) ?: [];
                if (isset($m[0])) $m = $m[0];
                return ['id' => $p->id, 'name' => $p->name, 'lat' => (float) $p->lat, 'lng' => (float) $p->lng,
                    'photo' => $m['formats']['small']['url'] ?? $m['url'] ?? null, 'full' => $m['url'] ?? null];
            });
        $cameras = DB::table('cameras')->where('site_id', $id)->get(['id', 'name', 'lat', 'lng']);
        return ['polygons' => $polygons, 'photo_points' => $points, 'cameras' => $cameras];
    }
}

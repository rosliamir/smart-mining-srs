<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Imports the reverse-engineered Strapi export (~/selgem-srs/data/*.json)
 * into the Postgres schema, resolving legacy Mongo _id references to new FKs.
 */
class LegacyImportSeeder extends Seeder
{
    private string $dir;
    private array $map = []; // table => [legacy_id => new_id]

    public function run(): void
    {
        $this->dir = getenv('HOME') . '/selgem-srs/data';
        if (! is_dir($this->dir)) {
            $this->command->warn("Export dir not found: {$this->dir} — skipping data import.");
            return;
        }

        // masters first
        $this->simple('regions', 'regions', fn ($r) => [
            'name' => $r['name'] ?? '—', 'lat' => $r['lat'] ?? null, 'lng' => $r['lng'] ?? null,
        ]);
        $this->simple('contractors', 'contractors', fn ($r) => ['name' => $r['name'] ?? '—']);
        $this->simple('site-types', 'site_types', fn ($r) => ['name' => $r['name'] ?? '—']);
        $this->simple('site-products', 'site_products', fn ($r) => ['name' => $r['name'] ?? '—']);
        $this->simple('equipment-item-types', 'equipment_item_types', fn ($r) => [
            'name' => $r['name'] ?? '—', 'code' => $r['code'] ?? null,
            'category' => $r['category'] ?? null, 'display_order' => $r['display_order'] ?? 0,
        ]);

        // sites
        $this->simple('sites', 'sites', fn ($r) => [
            'name' => $r['name'] ?? '—', 'site_code' => $r['site_code'] ?? null,
            'region_id' => $this->fk('regions', $r['region'] ?? null),
            'contractor_id' => $this->fk('contractors', $r['contractor'] ?? null),
            'type' => $r['type'] ?? null, 'product' => $r['product'] ?? null,
            'supervisor' => is_string($r['supervisor'] ?? null) ? $r['supervisor'] : null,
            'is_activated' => (bool)($r['is_activated'] ?? true),
            'lat' => $r['lat'] ?? null, 'lng' => $r['lng'] ?? null,
            'total_acreage' => $this->num($r['total_acreage'] ?? null),
            'reserve_land' => $this->num($r['reserve_land'] ?? null), 'remaining_land' => $this->num($r['remaining_land'] ?? null),
            'permit_date' => $this->date($r['permit_date'] ?? null),
            'permit_date_end' => $this->date($r['permit_date_end'] ?? null),
            'luas_permit_date' => $this->date($r['luas_permit_date'] ?? null),
        ]);
        // set PostGIS point from lat/lng
        DB::statement("UPDATE sites SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE lat IS NOT NULL AND lng IS NOT NULL");

        $this->simple('cameras', 'cameras', fn ($r) => [
            'name' => $r['name'] ?? '—', 'type' => $r['type'] ?? 'cctv', 'location' => $r['location'] ?? null,
            'lat' => $r['lat'] ?? null, 'lng' => $r['lng'] ?? null, 'hls_url' => $r['hlsUrl'] ?? null,
            'site_id' => $this->fk('sites', $r['site'] ?? null),
        ]);

        $this->simple('employees', 'employees', fn ($r) => [
            'name' => $r['name'] ?? '—', 'type' => $r['type'] ?? null, 'job_level' => $r['job_level'] ?? null,
            'is_activated' => (bool)($r['is_activated'] ?? true), 'is_deleted' => (bool)($r['is_deleted'] ?? false),
        ]);

        // users + roles
        $this->users();

        // weekly reports (header) + activity log
        $this->simple('weekly-reports', 'weekly_reports', fn ($r) => [
            'document_no' => $r['document_no'] ?? null,
            'site_id' => $this->fk('sites', $r['site'] ?? null),
            'contractor_id' => $this->fk('contractors', $r['contractor'] ?? null),
            'prepared_by' => $this->fk('users', $r['prepared_by'] ?? null),
            'reviewed_by' => is_string($r['reviewed_by'] ?? null) ? $r['reviewed_by'] : null,
            'status' => $r['status'] ?? 'draft', 'cycle_type' => $r['cycle_type'] ?? null,
            'start_effective_date' => $this->date($r['start_effective_date'] ?? null),
            'end_effective_date' => $this->date($r['end_effective_date'] ?? null),
        ]);

        $this->simple('reports', 'reports', fn ($r) => [
            'name' => $r['name'] ?? null, 'date' => $this->date($r['date'] ?? null),
            'type' => $r['type'] ?? null, 'severity' => $r['severity'] ?? null, 'status' => $r['status'] ?? 'New',
            'scope' => $r['scope'] ?? null, 'issue' => $r['issue'] ?? null, 'action' => $r['action'] ?? null, 'remarks' => $r['remarks'] ?? null,
            'site_id' => $this->fk('sites', $r['site'] ?? null), 'user_id' => $this->fk('users', $r['user'] ?? null),
        ]);

        ->simple('monitoring-reports', 'monitoring_reports', fn ($r) => [
            'name' => $r['name'] ?? null, 'type' => $r['type'] ?? null,
            'published_at' => $this->date($r['published_at'] ?? ($r['createdAt'] ?? null)),
            'files' => isset($r['files']) ? json_encode($r['files']) : null,
        ]);

        $this->command->info('Legacy import complete.');
        foreach ($this->map as $tbl => $ids) {
            $this->command->line(sprintf('  %-22s %d rows', $tbl, count($ids)));
        }
    }

    /** Load a JSON export file. Each record may store its id as id or _id. */
    private function load(string $file): array
    {
        $path = "{$this->dir}/{$file}.json";
        if (! is_file($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function simple(string $file, string $table, callable $map): void
    {
        $rows = $this->load($file);
        $this->map[$table] ??= [];
        foreach ($rows as $r) {
            $legacy = $this->legacyId($r);
            $data = $map($r) + ['legacy_id' => $legacy, 'created_at' => now(), 'updated_at' => now()];
            $id = DB::table($table)->insertGetId($data);
            if ($legacy) $this->map[$table][$legacy] = $id;
        }
    }

    private function users(): void
    {
        $rows = $this->load('users');
        $this->map['users'] ??= [];
        foreach ($rows as $r) {
            $legacy = $this->legacyId($r);
            $id = DB::table('users')->insertGetId([
                'name' => $r['username'] ?? ($r['email'] ?? 'user'),
                'username' => $r['username'] ?? null,
                'email' => $r['email'] ?? ($legacy.'@imported.local'),
                'password' => Hash::make('password'),
                'position' => $r['position'] ?? null,
                'employee_id' => is_string($r['employee_id'] ?? null) ? $r['employee_id'] : null,
                'blocked' => (bool)($r['blocked'] ?? false),
                'confirmed' => (bool)($r['confirmed'] ?? true),
                'legacy_id' => $legacy, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($legacy) $this->map['users'][$legacy] = $id;

            // attach role (role flattened to name string in export)
            $roleName = is_array($r['role'] ?? null) ? ($r['role']['type'] ?? null) : null;
            $roleName ??= $this->roleSlug($r['role'] ?? null);
            if ($roleName) {
                \App\Models\User::find($id)?->assignRole($roleName);
            }
        }
    }

    private function roleSlug($role): ?string
    {
        if (! is_string($role)) return null;
        return match (strtolower($role)) {
            'super admin' => 'super_admin', 'site officer' => 'site_officer',
            'manager' => 'manager', 'executive' => 'executive', 'po' => 'po',
            'authenticated' => 'authenticated', 'public' => 'public', default => null,
        };
    }

    private function legacyId(array $r): ?string
    {
        return $r['_id'] ?? $r['id'] ?? null;
    }

    /** Resolve a legacy relation value (string id, or object with id/_id, or name) to a new FK. */
    private function fk(string $table, $ref): ?int
    {
        if ($ref === null) return null;
        $legacy = is_array($ref) ? ($ref['_id'] ?? $ref['id'] ?? null) : $ref;
        if ($legacy && isset($this->map[$table][$legacy])) return $this->map[$table][$legacy];
        // export may have flattened relation to a NAME; try name lookup
        $name = is_array($ref) ? ($ref['name'] ?? null) : (is_string($ref) ? $ref : null);
        if ($name) {
            $col = $table === 'users' ? 'username' : 'name';
            $row = DB::table($table)->where($col, $name)->first();
            if ($row) return $row->id;
        }
        return null;
    }

    /** Coerce a messy value ("7,037,879.64 MT", " 2,564 ") to a float or null. */
    private function num($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float) $v;
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $v));
        return is_numeric($clean) ? (float) $clean : null;
    }

    private function date($v): ?string
    {
        if (! $v) return null;
        try { return \Carbon\Carbon::parse($v)->toDateString(); } catch (\Throwable) { return null; }
    }
}

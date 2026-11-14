<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\KnowledgeBase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Employee Self-Service bootstrap:
 *  - ESS roles (staff / ess_supervisor / ess_hr) — separate from the F001 roles so staff never gain F001 rights
 *  - one login per employee with a staff code (username = code, e.g. KS0197; demo password "password")
 *  - leave types with Employment Act 1955 minimum entitlements, 2026 balances
 *  - fixed-date public holidays (lunar holidays must be added by HR)
 *  - HR policy entries in the knowledge base
 * Idempotent: safe to re-run.
 */
class EssSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['staff', 'ess_supervisor', 'ess_hr'] as $r) Role::findOrCreate($r, 'web');

        // ── staff accounts ──
        $pwd = Hash::make('password');
        $emps = DB::table('employees')->whereNotNull('employee_id')->where('employee_id', '<>', '')->get();
        foreach ($emps as $e) {
            $u = User::where('username', $e->employee_id)->first() ?? new User();
            if (!$u->exists) {
                $u->forceFill(['name' => $e->name, 'username' => $e->employee_id, 'email' => strtolower($e->employee_id) . '@kssb.local',
                    'password' => $pwd, 'employee_id' => $e->employee_id, 'position' => $e->job_level,
                    'company' => 'KSSB', 'confirmed' => false, 'blocked' => false])->save();   // confirmed=false: no admin-panel access
            }
            $role = match (true) {
                in_array($e->job_level, ['pengurus', 'penolong_pengurus']) => 'ess_hr',
                in_array($e->job_level, ['eksekutif_kanan', 'eksekutif', 'eksekutif_muda', 'penyelia']) => 'ess_supervisor',
                default => 'staff',
            };
            $u->syncRoles(array_unique(['staff', $role]));
        }
        // super admins can approve and publish memos
        User::role('super_admin')->get()->each(fn ($u) => $u->assignRole('ess_hr'));

        // ── leave types (Employment Act 1955 as amended 2022 where applicable) ──
        $types = [
            ['annual', 'Annual leave', 'Cuti tahunan', 'fa-solid fa-umbrella-beach', '#2563eb', 0, true, false, 3, 0, ['supervisor', 'hr'], true],
            ['emergency', 'Emergency leave', 'Cuti kecemasan', 'fa-solid fa-truck-medical', '#f97316', 0, false, false, 0, 3, ['supervisor'], true],
            ['sick', 'Sick leave (MC)', 'Cuti sakit (MC)', 'fa-solid fa-notes-medical', '#e11d48', 0, true, true, 0, 7, ['supervisor'], true],
            ['hospital', 'Hospitalisation leave', 'Cuti hospital', 'fa-solid fa-hospital', '#be123c', 60, false, true, 0, 14, ['hr'], true],
            ['maternity', 'Maternity leave', 'Cuti bersalin', 'fa-solid fa-baby', '#db2777', 98, false, true, 14, 0, ['hr'], true],
            ['paternity', 'Paternity leave', 'Cuti paterniti', 'fa-solid fa-baby-carriage', '#7c3aed', 7, false, true, 0, 7, ['hr'], true],
            ['compassionate', 'Compassionate leave', 'Cuti ihsan', 'fa-solid fa-hand-holding-heart', '#0f766e', 3, false, false, 0, 7, ['supervisor'], true],
            ['replacement', 'Replacement leave', 'Cuti gantian', 'fa-solid fa-arrows-rotate', '#0891b2', 0, false, false, 1, 0, ['supervisor'], true],
            ['unpaid', 'Unpaid leave', 'Cuti tanpa gaji', 'fa-solid fa-wallet', '#64748b', 0, false, false, 7, 0, ['supervisor', 'hr'], false],
        ];
        foreach ($types as $i => [$code, $name, $ms, $icon, $color, $days, $svc, $att, $notice, $back, $chain, $deduct]) {
            DB::table('leave_types')->updateOrInsert(['code' => $code], [
                'name' => $name, 'name_ms' => $ms, 'icon' => $icon, 'color' => $color, 'default_days' => $days, 'service_based' => $svc,
                'requires_attachment' => $att, 'min_notice_days' => $notice, 'max_backdate_days' => $back, 'approval_chain' => json_encode($chain),
                'deducts_balance' => $deduct, 'sort' => $i, 'updated_at' => now(), 'created_at' => now()]);
        }

        // ── 2026 & 2027 balances. Join dates are not in the data, so everyone starts at the < 2 years tier
        //    (annual 8, sick 14) until HR updates entitlements.
        $lt = DB::table('leave_types')->get()->keyBy('code');
        foreach ([2026, 2027] as $year) foreach ($emps as $e) foreach ($lt as $t) {
            if (in_array($t->code, ['emergency', 'unpaid'])) continue;   // emergency draws on annual; unpaid has no balance
            $ent = match ($t->code) { 'annual' => 8, 'sick' => 14, default => (float) $t->default_days };
            DB::table('leave_balances')->insertOrIgnore(['employee_id' => $e->id, 'leave_type_id' => $t->id, 'year' => $year,
                'entitled' => $ent, 'carried' => 0, 'used' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        // ── fixed-date public holidays observed in Selangor. Lunar holidays (Hari Raya, CNY, Deepavali, Wesak, ...)
        //    change every year and must be added by HR from the official gazette.
        $fixed = [['01-01', "New Year's Day", 'selangor'], ['05-01', 'Labour Day', 'national'], ['08-31', 'National Day', 'national'],
            ['09-16', 'Malaysia Day', 'national'], ['12-11', "Birthday of the Sultan of Selangor", 'selangor'], ['12-25', 'Christmas Day', 'national']];
        foreach ([2026, 2027] as $y) {
            foreach ($fixed as [$md, $n, $scope]) DB::table('public_holidays')->insertOrIgnore(['date' => "$y-$md", 'name' => $n, 'scope' => $scope, 'provisional' => false, 'created_at' => now(), 'updated_at' => now()]);
            $agong = date('Y-m-d', strtotime("first monday of june $y"));   // Agong's birthday: first Monday of June
            DB::table('public_holidays')->insertOrIgnore(['date' => $agong, 'name' => "Birthday of the Yang di-Pertuan Agong", 'scope' => 'national', 'provisional' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        // ── one system memo so the memo board isn't empty (no invented company announcements)
        if (!DB::table('memos')->exists()) {
            DB::table('memos')->insert(['title' => 'Portal Layan Diri Pekerja (ESS) kini dibuka', 'category' => 'hr', 'priority' => 'important', 'pinned' => true, 'requires_ack' => true,
                'body' => "Semua staf kini boleh clock-in/clock-out, memohon cuti, kerja lebih masa, time-off dan tuntutan melalui portal ESS.\n\nLog masuk menggunakan kod staf anda (contoh: KS0123). Sila tukar kata laluan selepas log masuk pertama.\n\nPolisi HR boleh dibaca di menu Polisi, dan anda boleh bertanya apa-apa soalan berkaitan polisi melalui butang Ask.",
                'author_user_id' => User::role('super_admin')->value('id'), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // ── office geofences (addresses from semesta.com.my "Hubungi"; geocoded with OpenStreetMap Nominatim).
        //    HQ is street-level on Jalan Indah (Menara Bank Rakyat, No. 1 Jalan Indah 14/8) — adjust on the Geofence tab if needed.
        if (\Illuminate\Support\Facades\Schema::hasTable('work_locations')) {
            foreach ([
                'HQ' => [3.074654, 101.516392, 'Ibu Pejabat KSSB — Menara Bank Rakyat, Jln Indah 14/8, Seksyen 14, Shah Alam'],
                'PSU' => [3.379936, 101.412587, 'Pejabat Cawangan Utara — Jln Aman Suria 1, Taman Aman Suria, Bestari Jaya'],
                'PSS' => [2.850183, 101.650488, 'Pejabat Cawangan Selatan — Jln Ambar 1, Taman Ambar, Dengkil'],
            ] as $code => [$lat, $lng, $name]) {
                DB::table('work_locations')->where('code', $code)->whereNull('lat')->update(['lat' => $lat, 'lng' => $lng, 'name' => $name, 'updated_at' => now()]);
            }
        }

        (new KnowledgeBase())->seedHrPolicies();
    }
}

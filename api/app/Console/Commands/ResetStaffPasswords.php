<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Replace the demo password on staff (self-service) accounts with random ones and write them to a CSV for HR to hand out.
 *   php artisan ess:reset-passwords --export=storage/app/staff-passwords.csv
 *   php artisan ess:reset-passwords --only-demo        # only accounts still using "password"
 */
class ResetStaffPasswords extends Command
{
    protected $signature = 'ess:reset-passwords {--export=storage/app/staff-passwords.csv : CSV path for the new credentials} {--only-demo : Skip accounts that already changed their password}';
    protected $description = 'Give every ESS staff account a random password and export the list (removes the demo password)';

    public function handle(): int
    {
        $users = User::role('staff')->whereNotNull('username')->orderBy('username')->get();
        $path = base_path($this->option('export'));
        @mkdir(dirname($path), 0750, true);
        $fh = fopen($path, 'w');
        fputcsv($fh, ['staff_code', 'name', 'login_email', 'temporary_password']);
        $n = 0;
        foreach ($users as $u) {
            if ($this->option('only-demo') && !Hash::check('password', $u->password)) continue;
            $pwd = Str::password(10, symbols: false);
            $u->forceFill(['password' => Hash::make($pwd)])->save();
            fputcsv($fh, [$u->username, $u->name, $u->email, $pwd]);
            $n++;
        }
        fclose($fh);
        @chmod($path, 0600);
        $this->info("$n staff passwords reset. Credentials written to $path (delete it after HR has distributed them).");
        return self::SUCCESS;
    }
}

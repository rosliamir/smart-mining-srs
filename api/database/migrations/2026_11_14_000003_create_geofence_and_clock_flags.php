<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Geofences (per site radius + office locations), selfie & verification flags on clock-in/out. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->unsignedInteger('geofence_m')->default(1000));

        Schema::create('work_locations', function (Blueprint $t) {
            $t->id();
            $t->string('code', 16)->unique();            // HQ | PSU | PSS | custom
            $t->string('name');
            $t->decimal('lat', 10, 6)->nullable();       // set by HR on the map
            $t->decimal('lng', 10, 6)->nullable();
            $t->unsignedInteger('radius_m')->default(300);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        DB::table('work_locations')->insert([
            ['code' => 'HQ', 'name' => 'Ibu Pejabat (HQ)', 'radius_m' => 300, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'PSU', 'name' => 'Pejabat Satelit Utara', 'radius_m' => 300, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'PSS', 'name' => 'Pejabat Satelit Selatan', 'radius_m' => 300, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('attendances', function (Blueprint $t) {
            $t->string('photo_in')->nullable();
            $t->string('photo_out')->nullable();
            $t->unsignedInteger('accuracy_m')->nullable();     // browser-reported GPS accuracy
            $t->string('in_place', 32)->nullable();            // nearest site / office code at clock-in
            $t->unsignedInteger('in_distance_m')->nullable();
            $t->boolean('in_fence')->nullable();
            $t->string('out_place', 32)->nullable();
            $t->unsignedInteger('out_distance_m')->nullable();
            $t->boolean('out_fence')->nullable();
            $t->jsonb('flags')->nullable();                    // ["outside_fence","same_spot","low_accuracy","no_gps","no_photo"]
            $t->timestamp('reviewed_at')->nullable();          // HR cleared the alert
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $t) => $t->dropConstrainedForeignId('reviewed_by'));
        Schema::table('attendances', fn (Blueprint $t) => $t->dropColumn(['photo_in', 'photo_out', 'accuracy_m', 'in_place', 'in_distance_m', 'in_fence',
            'out_place', 'out_distance_m', 'out_fence', 'flags', 'reviewed_at', 'review_note']));
        Schema::dropIfExists('work_locations');
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn('geofence_m'));
    }
};

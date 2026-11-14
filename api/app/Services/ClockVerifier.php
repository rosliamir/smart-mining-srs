<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Checks a clock-in/out: nearest geofence, selfie storage and buddy-punching patterns. */
class ClockVerifier
{
    public const SAME_SPOT_M = 25;        // two staff within this distance…
    public const SAME_SPOT_MIN = 3;       // …within this many minutes = suspicious
    public const LOW_ACCURACY_M = 150;    // GPS accuracy worse than this is flagged

    /** Nearest active geofence (sites + offices with coordinates). */
    public function nearest(float $lat, float $lng): ?array
    {
        $r = DB::selectOne("
            WITH p AS (SELECT ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography g)
            SELECT * FROM (
              SELECT 'site' kind, s.site_code code, s.name, s.geofence_m radius, round(ST_Distance(s.geom::geography, p.g)) dist
                FROM sites s, p WHERE s.geom IS NOT NULL AND s.geofence_m > 0
              UNION ALL
              SELECT 'office', w.code, w.name, w.radius_m, round(ST_Distance(ST_SetSRID(ST_MakePoint(w.lng, w.lat), 4326)::geography, p.g))
                FROM work_locations w, p WHERE w.active AND w.lat IS NOT NULL AND w.lng IS NOT NULL
            ) x ORDER BY dist LIMIT 1", [$lng, $lat]);
        return $r ? ['kind' => $r->kind, 'code' => $r->code, 'name' => $r->name, 'radius' => (int) $r->radius,
            'dist' => (int) $r->dist, 'inside' => $r->dist <= $r->radius] : null;
    }

    /** Store a base64 data-URL selfie on the public disk under an unguessable name; returns the relative path. */
    public function savePhoto(?string $dataUrl, int $empId, string $dir): ?string
    {
        if (!$dataUrl || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#', $dataUrl, $m)) return null;
        $bin = base64_decode($m[2], true);
        abort_if($bin === false || strlen($bin) > 2_500_000, 422, 'Gambar selfie tidak sah atau terlalu besar.');
        abort_unless(@getimagesizefromstring($bin), 422, 'Fail selfie bukan gambar.');
        $path = 'selfies/' . now()->format('Y-m') . "/{$empId}-{$dir}-" . Str::random(24) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        Storage::disk('public')->put($path, $bin);
        return $path;
    }

    /** Other employees who clocked in at practically the same spot and time today. */
    public function sameSpot(int $attId, int $empId, string $date, float $lat, float $lng, string $time): array
    {
        return DB::select("
            SELECT a.id, e.name, e.employee_id code, to_char(a.timeclock_in,'HH24:MI') t,
                   round(ST_Distance(ST_SetSRID(ST_MakePoint(a.lng,a.lat),4326)::geography, ST_SetSRID(ST_MakePoint(?,?),4326)::geography)) dist
            FROM attendances a JOIN employees e ON e.id = a.employee_id
            WHERE a.date = ? AND a.source = 'ess' AND a.id <> ? AND a.employee_id <> ? AND a.lat IS NOT NULL AND a.lng IS NOT NULL
              AND abs(extract(epoch FROM a.timeclock_in - ?::time)) <= ?
              AND ST_DWithin(ST_SetSRID(ST_MakePoint(a.lng,a.lat),4326)::geography, ST_SetSRID(ST_MakePoint(?,?),4326)::geography, ?)",
            [$lng, $lat, $date, $attId, $empId, $time, self::SAME_SPOT_MIN * 60, $lng, $lat, self::SAME_SPOT_M]);
    }

    public static function photoUrl(?string $path): ?string { return $path ? asset('storage/' . $path) : null; }

    public const FLAG_LABEL = [
        'outside_fence' => 'Di luar geofence', 'same_spot' => 'Titik & masa sama dengan staf lain', 'low_accuracy' => 'GPS kurang tepat',
        'no_gps' => 'Tiada GPS', 'no_photo' => 'Tiada selfie',
    ];
}

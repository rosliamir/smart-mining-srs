<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [ApiController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [ApiController::class, 'me']);
    Route::post('/logout', [ApiController::class, 'logout']);

    Route::get('/dashboard', [ApiController::class, 'dashboard']);
    Route::get('/sales', [ApiController::class, 'sales']);
    Route::get('/sales/daily', [ApiController::class, 'salesDaily']);
    Route::post('/sync', [ApiController::class, 'sync']);
    Route::get('/map', [ApiController::class, 'map']);
    // ── Employee Self-Service ──
    Route::prefix('ess')->controller(\App\Http\Controllers\EssController::class)->group(function () {
        Route::get('/home', 'home');
        Route::post('/clock/{dir}', 'clock')->whereIn('dir', ['in', 'out']);
        Route::get('/attendance', 'attendance');
        Route::get('/balances', 'balances');
        Route::get('/requests', 'requests');
        Route::post('/requests/{type}', 'store');
        Route::post('/requests/{id}/cancel', 'cancel')->whereNumber('id');
        Route::get('/approvals', 'approvals');
        Route::post('/approvals/{id}', 'act');
        Route::get('/notifications', 'notifications');
        Route::post('/notifications/read', 'readNotifications');
        Route::get('/memos', 'memos');
        Route::get('/memos/{id}', 'memo');
        Route::post('/memos/{id}/ack', 'ackMemo');
        Route::post('/memos', 'storeMemo');
        Route::get('/holidays', 'holidays');
        Route::get('/team', 'team');
        Route::get('/report', 'report');
        Route::post('/password', 'password');
    });
    Route::controller(\App\Http\Controllers\AttendanceGeoController::class)->group(function () {
        Route::get('/ess/me/trend', 'myTrend');
        Route::get('/ess/me/locations', 'myLocations');
        Route::get('/hr/attendance/map', 'hrMap');
        Route::get('/hr/attendance/heatmap', 'hrHeatmap');
        Route::get('/hr/attendance/trend', 'hrTrend');
        Route::get('/hr/staff', 'staffList');
        Route::get('/hr/attendance/alerts', 'alerts');
        Route::post('/hr/attendance/{id}/review', 'review')->whereNumber('id');
        Route::get('/hr/geofences', 'geofences');
        Route::put('/hr/geofences/{kind}/{id}', 'updateGeofence')->whereIn('kind', ['site', 'office'])->whereNumber('id');
        Route::get('/hr/manpower', 'manpower');
    });

    Route::get('/kb', [\App\Http\Controllers\KnowledgeBaseController::class, 'index']);
    Route::post('/kb', [\App\Http\Controllers\KnowledgeBaseController::class, 'store']);
    Route::post('/kb/refresh', [\App\Http\Controllers\KnowledgeBaseController::class, 'refresh']);
    Route::get('/kb/{id}', [\App\Http\Controllers\KnowledgeBaseController::class, 'show']);
    Route::put('/kb/{id}', [\App\Http\Controllers\KnowledgeBaseController::class, 'update']);
    Route::delete('/kb/{id}', [\App\Http\Controllers\KnowledgeBaseController::class, 'destroy']);
    Route::post('/ask', [\App\Http\Controllers\KnowledgeBaseController::class, 'ask']);
    Route::get('/attendance/insights', [\App\Http\Controllers\AttendanceInsightsController::class, 'index']);
    Route::get('/monitoring/insights', [\App\Http\Controllers\MonitoringInsightsController::class, 'index']);
    Route::get('/insights', [\App\Http\Controllers\InsightsController::class, 'index']);
    Route::get('/sites', [ApiController::class, 'sites']);
    Route::get('/sites/{id}/geo', [ApiController::class, 'siteGeo']);
    Route::get('/sites/{id}', [ApiController::class, 'site']);
    Route::get('/sites/{id}/media', [ApiController::class, 'siteMedia']);
    Route::get('/regions', [ApiController::class, 'regions']);
    Route::get('/contractors', [ApiController::class, 'contractors']);
    Route::get('/employees', [ApiController::class, 'employees']);
    Route::get('/cameras', [ApiController::class, 'cameras']);
    Route::get('/reports', [ApiController::class, 'reports']);
    Route::get('/monitoring-reports', [ApiController::class, 'monitoringReports']);
    Route::get('/roles', [ApiController::class, 'roles']);
    Route::get('/users', [ApiController::class, 'users']);
    Route::get('/attendances', [ApiController::class, 'attendances']);
    Route::get('/attendances/monthly', [ApiController::class, 'attendanceMonthly']);
    Route::get('/attendances/grid', [ApiController::class, 'attendanceGrid']);
    Route::get('/weekly-reports', [ApiController::class, 'weeklyReports']);
    Route::get('/weekly-reports/{id}', [ApiController::class, 'weeklyReport']);
    Route::post('/weekly-reports/{id}/transition', [ApiController::class, 'transition']);
});

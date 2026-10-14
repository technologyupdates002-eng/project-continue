<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDataController;
use App\Http\Controllers\Admin\BackupAdminController;
use App\Http\Controllers\Admin\SuperAdminController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return File::get(public_path('index.html'));
})->name('dashboard');

/*
|--------------------------------------------------------------------------
| Super Admin area
|--------------------------------------------------------------------------
| Protected by the `admin` middleware which re-validates a session-stored
| JWT and enforces Admin/Super Admin access. All backup/restore endpoints are
| therefore independently authorized server-side (not just hidden in the UI).
*/
/**
 * Admin login compatibility routes.
 *
 * Authentication is owned by the React SPA. These aliases remain only so
 * old bookmarks cannot open the retired Blade login template.
 */
Route::get('/admin/login', fn() => redirect('/super-admin'))->name('admin.login');
Route::get('/admin-panel/login', fn() => redirect('/super-admin'))->name('admin.panel.login');

Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin-panel/login', [AdminAuthController::class, 'login'])->name('admin.panel.login.post');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

/**
 * Unified Admin Dashboard via SPA
 * Redirect all old Blade routes to the unified super-admin SPA dashboard.
 * All functionality is now in the React frontend at /super-admin.
 */
Route::middleware(['admin'])->group(function () {
    // Redirect dashboard and section pages to SPA super-admin
    Route::get('/admin', fn() => redirect('/super-admin'))->name('admin.dashboard');
    Route::get('/admin/backups', fn() => redirect('/super-admin'))->name('admin.backups');
    Route::get('/admin/settings', fn() => redirect('/super-admin'))->name('admin.settings');
    Route::get('/admin/users', fn() => redirect('/super-admin'))->name('admin.users');
    Route::get('/admin/groups', fn() => redirect('/super-admin'))->name('admin.groups');
    Route::get('/admin/transactions', fn() => redirect('/super-admin'))->name('admin.transactions');
    Route::get('/admin/reports', fn() => redirect('/super-admin'))->name('admin.reports');
    Route::get('/admin/audit-logs', fn() => redirect('/super-admin'))->name('admin.audit-logs');

    // JSON API consumed by the Super Admin UI.
    Route::prefix('/admin/api')->group(function () {
        Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);

        Route::get('/backups', [BackupAdminController::class, 'data']);
        Route::post('/backups', [BackupAdminController::class, 'store']);
        Route::post('/backups/prune', [BackupAdminController::class, 'prune']);
        Route::get('/backups/settings', [BackupAdminController::class, 'settings']);
        Route::put('/backups/settings', [BackupAdminController::class, 'settings']);
        Route::get('/backups/{id}', [BackupAdminController::class, 'show']);
        Route::post('/backups/{id}/verify', [BackupAdminController::class, 'verify']);
        Route::get('/backups/{id}/download', [BackupAdminController::class, 'download']);
        Route::delete('/backups/{id}', [BackupAdminController::class, 'destroy']);
        Route::post('/backups/{id}/restore', [BackupAdminController::class, 'restore']);

        Route::get('/users', [AdminDataController::class, 'usersData']);
        Route::get('/groups', [AdminDataController::class, 'groupsData']);
        Route::get('/transactions', [AdminDataController::class, 'transactionsData']);
        Route::get('/reports', [AdminDataController::class, 'reportsData']);
        Route::get('/audit-logs', [AdminDataController::class, 'auditData']);

        Route::prefix('system')->group(function () {
            Route::get('/health', [SuperAdminController::class, 'systemHealth']);
            Route::get('/queue', [SuperAdminController::class, 'queueOverview']);
            Route::get('/queue/failed', [SuperAdminController::class, 'queueFailed']);
            Route::post('/queue/failed/{jobId}/retry', [SuperAdminController::class, 'retryFailedJob']);
            Route::delete('/queue/failed/{jobId}', [SuperAdminController::class, 'deleteFailedJob']);
            Route::post('/queue/failed/retry-all', [SuperAdminController::class, 'retryAllFailedJobs']);
            Route::delete('/queue/failed/flush', [SuperAdminController::class, 'flushFailedJobs']);

            Route::get('/cache', [SuperAdminController::class, 'cacheOverview']);
            Route::post('/cache/clear', [SuperAdminController::class, 'clearCache']);
            Route::post('/cache/redis/flush', [SuperAdminController::class, 'clearRedis']);
            Route::get('/cache/stats', [SuperAdminController::class, 'cacheStats']);

            Route::get('/financial', [SuperAdminController::class, 'financialOverview']);
            Route::get('/database', [SuperAdminController::class, 'databaseHealth']);
            Route::get('/bank', [SuperAdminController::class, 'bankMonitoring']);
            Route::get('/trace', [SuperAdminController::class, 'transactionTrace']);
        });
    });
});

// SPA fallback: serve React frontend for all unmatched non-API routes
Route::fallback(function () {
    return File::get(public_path('index.html'));
});

<?php

use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\PurchaseReportController;
use App\Http\Controllers\Api\SalesWeeklyReportController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Mobile app API, same path as the old quinoscloud one: /api/cloud2/transactions/getTransactionByDate/{token}/{store}/{date}
Route::get('/cloud2/transactions/getTransactionByDate/{token}/{store}/{date}', \App\Http\Controllers\Api\MobileTransactionController::class)
    ->where(['token' => '[A-Za-z0-9]{20,128}', 'date' => '\d{4}-\d{2}-\d{2}'])
    ->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'client.resolve'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');

    Route::get('/settings/shifts', [ShiftController::class, 'show']);
    Route::put('/settings/shifts', [ShiftController::class, 'update']);

    Route::get('/sync/calendar', [SyncController::class, 'calendar']);
    Route::post('/sync/days', [SyncController::class, 'days'])->middleware('throttle:30,1');
    Route::post('/sync/cancel', [SyncController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('/sync/run-now', [SyncController::class, 'runNow'])->middleware('throttle:10,1');
    Route::post('/sync/master', [SyncController::class, 'master'])->middleware('throttle:10,1');

    Route::get('/dashboard', DashboardController::class);
    Route::get('/master/{kind}', [MasterController::class, 'index']);
    Route::get('/reports/purchase', PurchaseReportController::class);
    Route::get('/reports/sales-weekly', SalesWeeklyReportController::class);
    Route::get('/reports/sales-weekly/export', [SalesWeeklyReportController::class, 'export']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::apiResource('clients', ClientController::class);
        Route::post('clients/{client}/mobile-api-token', [ClientController::class, 'generateMobileToken']);
        Route::delete('clients/{client}/mobile-api-token', [ClientController::class, 'removeMobileToken']);
        Route::apiResource('users', UserController::class)->except('show');
    });
});

// Machine API: separate from browser sessions and tenant-header selection.
Route::prefix('runner/v1')->middleware(\App\Http\Middleware\AuthenticateRunner::class)->group(function () {
    Route::get('/tenants', \App\Http\Controllers\Api\Runner\TenantController::class);
    Route::get('/tenants/{id}/sync-days', \App\Http\Controllers\Api\Runner\SyncDaysController::class)->whereNumber('id');
    Route::post('/imports', [\App\Http\Controllers\Api\Runner\ImportController::class, 'begin']);
    Route::put('/imports/{id}/chunks/{sequence}', [\App\Http\Controllers\Api\Runner\ImportController::class, 'chunk'])->whereNumber('sequence');
    Route::post('/imports/{id}/commit', [\App\Http\Controllers\Api\Runner\ImportController::class, 'commit']);
});
Route::prefix('admin/runner-tokens')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('', [\App\Http\Controllers\Api\Admin\RunnerTokenController::class, 'index']);
    Route::post('', [\App\Http\Controllers\Api\Admin\RunnerTokenController::class, 'store']);
    Route::post('/{runnerToken}/revoke', [\App\Http\Controllers\Api\Admin\RunnerTokenController::class, 'revoke']);
    Route::delete('/{runnerToken}', [\App\Http\Controllers\Api\Admin\RunnerTokenController::class, 'destroy']);
});

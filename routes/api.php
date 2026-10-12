<?php

use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\PurchaseReportController;
use App\Http\Controllers\Api\SalesWeeklyReportController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'client.resolve'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/settings/shifts', [ShiftController::class, 'show']);
    Route::put('/settings/shifts', [ShiftController::class, 'update']);

    Route::get('/sync/calendar', [SyncController::class, 'calendar']);
    Route::post('/sync/days', [SyncController::class, 'days'])->middleware('throttle:30,1');
    Route::post('/sync/master', [SyncController::class, 'master'])->middleware('throttle:10,1');

    Route::get('/master/{kind}', [MasterController::class, 'index']);
    Route::get('/reports/purchase', PurchaseReportController::class);
    Route::get('/reports/sales-weekly', SalesWeeklyReportController::class);
    Route::get('/reports/sales-weekly/export', [SalesWeeklyReportController::class, 'export']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::apiResource('clients', ClientController::class);
        Route::apiResource('users', UserController::class)->except('show');
    });
});

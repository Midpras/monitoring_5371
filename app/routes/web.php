<?php

use App\Http\Controllers\AdminUploadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProgressUploadController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [DashboardController::class, 'health']);
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::prefix('api/dashboard')->group(function () {
    Route::get('summary', [DashboardController::class, 'summary']);
    Route::get('timeseries', [DashboardController::class, 'timeSeries']);
    Route::get('ppl', [DashboardController::class, 'ppl']);
    Route::get('pml', [DashboardController::class, 'pml']);
    Route::get('breakdown', [DashboardController::class, 'breakdown']);
    Route::get('filters', [DashboardController::class, 'filters']);
});

Route::get('/admin/login', [AuthController::class, 'create'])->name('login');
Route::post('/admin/login', [AuthController::class, 'store'])->middleware('throttle:5,1');
Route::redirect('/login', '/admin/login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin', [AdminUploadController::class, 'index'])->name('admin.uploads');
    Route::post('/admin/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::prefix('api/admin/progress-uploads')->middleware('admin')->group(function () {
        Route::get('/', [ProgressUploadController::class, 'index']);
        Route::post('validate', [ProgressUploadController::class, 'validateUpload']);
        Route::post('{progressUpload}/confirm', [ProgressUploadController::class, 'confirm']);
        Route::delete('{progressUpload}', [ProgressUploadController::class, 'destroy']);
        Route::get('{progressUpload}', [ProgressUploadController::class, 'show']);
    });
});

<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {

    // ── Auth (public) ────────────────────────────────────
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('api.v1.auth.refresh');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('api.v1.auth.forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('api.v1.auth.reset-password');
    });

    // ── Authenticated ────────────────────────────────────
    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

        Route::get('me', [UserController::class, 'me'])->name('api.v1.user.me');
        Route::put('me', [UserController::class, 'update'])->name('api.v1.user.update');
    });
});

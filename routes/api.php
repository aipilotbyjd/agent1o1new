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
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('api.v1.auth.register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('api.v1.auth.login');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1')->name('api.v1.auth.refresh');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('api.v1.auth.forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('api.v1.auth.reset-password');
        Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed')->name('verification.verify');
    });

    // ── Authenticated ────────────────────────────────────
    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::post('auth/resend-verification', [AuthController::class, 'resendVerificationEmail'])->middleware('throttle:3,1')->name('api.v1.auth.resend-verification');

        // ── User Profile ────────────────────────────────
        Route::get('me', [UserController::class, 'me'])->name('api.v1.user.me');
        Route::put('me', [UserController::class, 'update'])->name('api.v1.user.update');
        Route::put('me/password', [UserController::class, 'changePassword'])->name('api.v1.user.change-password');
        Route::post('me/avatar', [UserController::class, 'uploadAvatar'])->name('api.v1.user.upload-avatar');
        Route::delete('me/avatar', [UserController::class, 'deleteAvatar'])->name('api.v1.user.delete-avatar');
        Route::delete('me', [UserController::class, 'destroy'])->name('api.v1.user.destroy');
    });
});

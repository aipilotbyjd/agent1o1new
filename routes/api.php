<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
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

        // ── Workspaces ───────────────────────────────────
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('api.v1.workspaces.index');
        Route::post('workspaces', [WorkspaceController::class, 'store'])->name('api.v1.workspaces.store');

        Route::middleware('workspace.role')->prefix('workspaces/{workspace}')->group(function (): void {
            Route::get('/', [WorkspaceController::class, 'show'])->name('api.v1.workspaces.show');
            Route::put('/', [WorkspaceController::class, 'update'])->name('api.v1.workspaces.update');
            Route::delete('/', [WorkspaceController::class, 'destroy'])->name('api.v1.workspaces.destroy');

            // ── Members ──────────────────────────────────
            Route::get('members', [WorkspaceMemberController::class, 'index'])->name('api.v1.workspaces.members.index');
            Route::put('members/{user}', [WorkspaceMemberController::class, 'update'])->name('api.v1.workspaces.members.update');
            Route::delete('members/{user}', [WorkspaceMemberController::class, 'destroy'])->name('api.v1.workspaces.members.destroy');
            Route::post('members/leave', [WorkspaceMemberController::class, 'leave'])->name('api.v1.workspaces.members.leave');

            // ── Workflows ────────────────────────────────
            Route::get('workflows', [WorkflowController::class, 'index'])->name('api.v1.workspaces.workflows.index');
            Route::post('workflows', [WorkflowController::class, 'store'])->name('api.v1.workspaces.workflows.store');
            Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->name('api.v1.workspaces.workflows.show');
            Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])->name('api.v1.workspaces.workflows.update');
            Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('api.v1.workspaces.workflows.destroy');
            Route::patch('workflows/{workflow}/activate', [WorkflowController::class, 'activate'])->name('api.v1.workspaces.workflows.activate');
            Route::patch('workflows/{workflow}/deactivate', [WorkflowController::class, 'deactivate'])->name('api.v1.workspaces.workflows.deactivate');
            Route::post('workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate'])->name('api.v1.workspaces.workflows.duplicate');

            // ── Invitations ──────────────────────────────
            Route::get('invitations', [InvitationController::class, 'index'])->name('api.v1.workspaces.invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])->name('api.v1.workspaces.invitations.store');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('api.v1.workspaces.invitations.destroy');
        });

        // ── Invitation Accept/Decline (not workspace-scoped) ─
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('api.v1.invitations.accept');
        Route::post('invitations/{token}/decline', [InvitationController::class, 'decline'])->name('api.v1.invitations.decline');
    });
});

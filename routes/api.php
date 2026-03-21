<?php

/*
|--------------------------------------------------------------------------
| API Routes — LinkFlow v1
|--------------------------------------------------------------------------
|
| Route layers (outermost → innermost):
|
|   1. Public        — No auth required (health, verify-email, webhooks)
|   2. Guest         — Auth routes for unauthenticated users (login, register)
|   3. Authenticated — Requires valid access token (auth:api)
|   4. Workspace     — Requires membership + resolves role/permissions ONCE
|                      via 'workspace.role' middleware. All nested models are
|                      scoped to the workspace via scopeBindings().
|
| Authorization strategy:
|   - 'workspace.role' middleware loads permissions once per request
|   - Controllers use $this->can(Permission::...) for authorization
|   - Form Requests check permissions in authorize() method
|
*/

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\CredentialTypeController;
use App\Http\Controllers\Api\V1\CreditController;
use App\Http\Controllers\Api\V1\ExecutionController;
use App\Http\Controllers\Api\V1\GitSyncController;
use App\Http\Controllers\Api\V1\GitSyncWebhookController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LogStreamingConfigController;
use App\Http\Controllers\Api\V1\NodeCategoryController;
use App\Http\Controllers\Api\V1\NodeController;
use App\Http\Controllers\Api\V1\OAuthCredentialController;
use App\Http\Controllers\Api\V1\PinnedNodeDataController;
use App\Http\Controllers\Api\V1\PollingTriggerController;
use App\Http\Controllers\Api\V1\SseController;
use App\Http\Controllers\Api\V1\StickyNoteController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VariableController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WebhookReceiverController;
use App\Http\Controllers\Api\V1\WorkflowBuilderController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowImportExportController;
use App\Http\Controllers\Api\V1\WorkflowShareController;
use App\Http\Controllers\Api\V1\WorkflowTemplateController;
use App\Http\Controllers\Api\V1\WorkflowVersionController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
use App\Http\Controllers\Api\V1\WorkspaceSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('v1.')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Public — No authentication required
    |----------------------------------------------------------------------
    */

    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]))->name('health');

    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'webhook/{uuid}', [WebhookReceiverController::class, 'handle'])
        ->middleware('throttle:webhook-receive')
        ->name('webhook.receive');

    Route::post('git-sync/webhook/{workspaceSlug}', [GitSyncWebhookController::class, 'handle'])
        ->name('git-sync.webhook');

    // ── Shared Workflows (public viewing) ───────────────────────
    Route::get('shared/{token}', [WorkflowShareController::class, 'viewPublic'])->name('shared.view');

    // ── OAuth2 Callback (no auth — redirect from provider) ──────
    Route::get('oauth/callback', [OAuthCredentialController::class, 'callback'])->name('oauth.callback');

    /*
    |----------------------------------------------------------------------
    | Guest — Authentication routes (unauthenticated users only)
    |----------------------------------------------------------------------
    */

    Route::prefix('auth')
        ->as('auth.')
        ->middleware('throttle:auth')
        ->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
        });

    /*
    |----------------------------------------------------------------------
    | Authenticated — Requires valid access token
    |----------------------------------------------------------------------
    */

    Route::middleware('auth:api')->group(function () {

        // ── Auth (post-login) ────────────────────────────────────────

        Route::prefix('auth')->as('auth.')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('resend-verification-email', [AuthController::class, 'resendVerificationEmail'])->name('resend-verification');
        });

        // ── User Profile ─────────────────────────────────────────────

        Route::prefix('user')->as('user.')->group(function () {
            Route::get('/', [UserController::class, 'me'])->name('show');
            Route::put('/', [UserController::class, 'update'])->name('update');
            Route::delete('/', [UserController::class, 'destroy'])->name('destroy');
            Route::put('password', [UserController::class, 'changePassword'])->name('password');
            Route::post('avatar', [UserController::class, 'uploadAvatar'])->name('avatar.upload');
            Route::delete('avatar', [UserController::class, 'deleteAvatar'])->name('avatar.delete');
        });

        // ── Invitations (accept/decline — no workspace context needed) ─

        Route::prefix('invitations/{token}')->as('invitations.')->group(function () {
            Route::post('accept', [InvitationController::class, 'accept'])->name('accept');
            Route::post('decline', [InvitationController::class, 'decline'])->name('decline');
        });

        // ── Workspaces (list + create — no membership needed) ────────

        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

        /*
        |------------------------------------------------------------------
        | Workspace-Scoped — Requires membership
        |------------------------------------------------------------------
        |
        | Middleware: 'workspace.role'
        |   → Verifies user is a member of the workspace
        |   → Loads role + permissions ONCE, caches on $request->attributes
        |   → Controllers use $this->can(Permission::...) for checks
        |
        | scopeBindings():
        |   → All nested model bindings ({workflow}, {credential}, etc.)
        |     are automatically scoped to {workspace}. Prevents cross-
        |     workspace data access at the routing layer.
        |
        */

        Route::prefix('workspaces/{workspace}')->as('workspaces.')
            ->middleware(['workspace.role'])
            ->scopeBindings()
            ->group(function () {

                // ── Workspace CRUD ───────────────────────────────────

                Route::get('/', [WorkspaceController::class, 'show'])->name('show');
                Route::put('/', [WorkspaceController::class, 'update'])->name('update');
                Route::delete('/', [WorkspaceController::class, 'destroy'])->name('destroy');

                // ── Members ──────────────────────────────────────────

                Route::prefix('members')->as('members.')->group(function () {
                    Route::get('/', [WorkspaceMemberController::class, 'index'])->name('index');
                    Route::put('{user}', [WorkspaceMemberController::class, 'update'])->name('update');
                    Route::delete('{user}', [WorkspaceMemberController::class, 'destroy'])->name('destroy');
                });

                Route::post('leave', [WorkspaceMemberController::class, 'leave'])->name('leave');

                // ── Invitations (manage — within workspace) ──────────

                Route::prefix('invitations')->as('invitations.')->group(function () {
                    Route::get('/', [InvitationController::class, 'index'])->name('index');
                    Route::post('/', [InvitationController::class, 'store'])->name('store');
                    Route::delete('{invitation}', [InvitationController::class, 'destroy'])->name('destroy');
                });

                // ── Workflows ────────────────────────────────────────

                Route::prefix('workflows')->as('workflows.')->group(function () {
                    Route::get('/', [WorkflowController::class, 'index'])->name('index');
                    Route::post('/', [WorkflowController::class, 'store'])->name('store');

                    Route::post('import', [WorkflowImportExportController::class, 'import'])->name('import');
                    Route::post('build', [WorkflowBuilderController::class, 'build'])->name('build');

                    Route::prefix('{workflow}')->group(function () {
                        Route::get('/', [WorkflowController::class, 'show'])->name('show');
                        Route::put('/', [WorkflowController::class, 'update'])->name('update');
                        Route::delete('/', [WorkflowController::class, 'destroy'])->name('destroy');
                        Route::post('activate', [WorkflowController::class, 'activate'])->name('activate');
                        Route::post('deactivate', [WorkflowController::class, 'deactivate'])->name('deactivate');
                        Route::post('duplicate', [WorkflowController::class, 'duplicate'])->name('duplicate');
                        Route::post('execute', [ExecutionController::class, 'store'])->middleware('throttle:execution-trigger')->name('execute');
                        Route::get('executions', [ExecutionController::class, 'workflowExecutions'])->name('executions.index');
                        Route::post('webhook', [WebhookController::class, 'store'])->name('webhook.store');
                        Route::post('polling-trigger', [PollingTriggerController::class, 'store'])->name('polling-trigger.store');
                        Route::get('export', [WorkflowImportExportController::class, 'export'])->name('export');

                        // ── Versions ─────────────────────────────────

                        Route::prefix('versions')->as('versions.')->group(function () {
                            Route::get('/', [WorkflowVersionController::class, 'index'])->name('index');
                            Route::post('/', [WorkflowVersionController::class, 'store'])->name('store');
                            Route::get('diff', [WorkflowVersionController::class, 'diff'])->name('diff');
                            Route::get('{version}', [WorkflowVersionController::class, 'show'])->name('show');
                            Route::post('{version}/publish', [WorkflowVersionController::class, 'publish'])->name('publish');
                            Route::post('{version}/rollback', [WorkflowVersionController::class, 'rollback'])->name('rollback');
                        });

                        // ── Shares ───────────────────────────────────

                        Route::prefix('shares')->as('shares.')->group(function () {
                            Route::get('/', [WorkflowShareController::class, 'index'])->name('index');
                            Route::post('/', [WorkflowShareController::class, 'store'])->name('store');
                            Route::put('{share}', [WorkflowShareController::class, 'update'])->name('update');
                            Route::delete('{share}', [WorkflowShareController::class, 'destroy'])->name('destroy');
                        });

                        // ── Sticky Notes ─────────────────────────────

                        Route::prefix('sticky-notes')->as('sticky-notes.')->group(function () {
                            Route::get('/', [StickyNoteController::class, 'index'])->name('index');
                            Route::post('/', [StickyNoteController::class, 'store'])->name('store');
                            Route::put('{stickyNote}', [StickyNoteController::class, 'update'])->name('update');
                            Route::delete('{stickyNote}', [StickyNoteController::class, 'destroy'])->name('destroy');
                        });

                        // ── Pinned Node Data ─────────────────────────

                        Route::prefix('pinned-data')->as('pinned-data.')->group(function () {
                            Route::get('/', [PinnedNodeDataController::class, 'index'])->name('index');
                            Route::post('/', [PinnedNodeDataController::class, 'store'])->name('store');
                            Route::post('{pinnedNodeData}/toggle', [PinnedNodeDataController::class, 'toggle'])->name('toggle');
                            Route::delete('{pinnedNodeData}', [PinnedNodeDataController::class, 'destroy'])->name('destroy');
                        });
                    });
                });

                // ── Credentials ──────────────────────────────────────

                Route::prefix('credentials')->as('credentials.')->group(function () {
                    Route::get('/', [CredentialController::class, 'index'])->name('index');
                    Route::post('/', [CredentialController::class, 'store'])->name('store');
                    Route::get('{credential}', [CredentialController::class, 'show'])->name('show');
                    Route::put('{credential}', [CredentialController::class, 'update'])->name('update');
                    Route::delete('{credential}', [CredentialController::class, 'destroy'])->name('destroy');
                    Route::post('{credential}/test', [CredentialController::class, 'test'])->name('test');
                });

                // ── Executions ───────────────────────────────────────

                Route::prefix('executions')->as('executions.')->group(function () {
                    Route::get('stats', [ExecutionController::class, 'stats'])->name('stats');
                    Route::get('compare', [ExecutionController::class, 'compare'])->name('compare');
                    Route::delete('bulk', [ExecutionController::class, 'bulkDestroy'])->name('bulk-destroy');
                    Route::get('stream-all', [SseController::class, 'streamWorkspace'])->name('stream-all');
                    Route::get('/', [ExecutionController::class, 'index'])->name('index');
                    Route::get('{execution}', [ExecutionController::class, 'show'])->name('show');
                    Route::delete('{execution}', [ExecutionController::class, 'destroy'])->name('destroy');
                    Route::get('{execution}/nodes', [ExecutionController::class, 'nodes'])->name('nodes');
                    Route::get('{execution}/logs', [ExecutionController::class, 'logs'])->name('logs');
                    Route::post('{execution}/retry', [ExecutionController::class, 'retry'])->name('retry');
                    Route::post('{execution}/cancel', [ExecutionController::class, 'cancel'])->name('cancel');
                    Route::post('{execution}/replay', [ExecutionController::class, 'replay'])->name('replay');
                    Route::get('{execution}/stream', [SseController::class, 'stream'])->name('stream');
                });

                // ── Webhooks ─────────────────────────────────────────

                Route::prefix('webhooks')->as('webhooks.')->group(function () {
                    Route::get('/', [WebhookController::class, 'index'])->name('index');
                    Route::get('{webhook}', [WebhookController::class, 'show'])->name('show');
                    Route::put('{webhook}', [WebhookController::class, 'update'])->name('update');
                    Route::delete('{webhook}', [WebhookController::class, 'destroy'])->name('destroy');
                });

                // ── Polling Triggers ─────────────────────────────────

                Route::prefix('polling-triggers')->as('polling-triggers.')->group(function () {
                    Route::get('/', [PollingTriggerController::class, 'index'])->name('index');
                    Route::get('{pollingTrigger}', [PollingTriggerController::class, 'show'])->name('show');
                    Route::put('{pollingTrigger}', [PollingTriggerController::class, 'update'])->name('update');
                    Route::delete('{pollingTrigger}', [PollingTriggerController::class, 'destroy'])->name('destroy');
                });

                // ── Variables ────────────────────────────────────────

                Route::prefix('variables')->as('variables.')->group(function () {
                    Route::get('/', [VariableController::class, 'index'])->name('index');
                    Route::post('/', [VariableController::class, 'store'])->name('store');
                    Route::get('{variable}', [VariableController::class, 'show'])->name('show');
                    Route::put('{variable}', [VariableController::class, 'update'])->name('update');
                    Route::delete('{variable}', [VariableController::class, 'destroy'])->name('destroy');
                });

                // ── Tags ─────────────────────────────────────────────

                Route::prefix('tags')->as('tags.')->group(function () {
                    Route::get('/', [TagController::class, 'index'])->name('index');
                    Route::post('/', [TagController::class, 'store'])->name('store');
                    Route::get('{tag}', [TagController::class, 'show'])->name('show');
                    Route::put('{tag}', [TagController::class, 'update'])->name('update');
                    Route::delete('{tag}', [TagController::class, 'destroy'])->name('destroy');
                    Route::post('{tag}/workflows', [TagController::class, 'attachWorkflows'])->name('workflows.attach');
                    Route::delete('{tag}/workflows', [TagController::class, 'detachWorkflows'])->name('workflows.detach');
                });

                // ── Activity Logs ────────────────────────────────────

                Route::prefix('activity-logs')->as('activity-logs.')->group(function () {
                    Route::get('/', [ActivityLogController::class, 'index'])->name('index');
                    Route::get('export', [ActivityLogController::class, 'export'])->name('export');
                    Route::get('{activityLog}', [ActivityLogController::class, 'show'])->name('show');
                });

                // ── Credits ─────────────────────────────────────────
                Route::prefix('credits')->as('credits.')->group(function () {
                    Route::get('balance', [CreditController::class, 'balance'])->name('balance');
                    Route::get('transactions', [CreditController::class, 'transactions'])->name('transactions');
                });

                // ── Workspace Settings ──────────────────────────────

                Route::prefix('settings')->as('settings.')->group(function () {
                    Route::get('/', [WorkspaceSettingController::class, 'show'])->name('show');
                    Route::put('/', [WorkspaceSettingController::class, 'update'])->name('update');
                });

                // ── OAuth2 Credential Flow ──────────────────────────

                Route::post('oauth/initiate', [OAuthCredentialController::class, 'initiate'])->name('oauth.initiate');

                // ── Log Streaming ───────────────────────────────────

                Route::prefix('log-streaming')->as('log-streaming.')->group(function () {
                    Route::get('/', [LogStreamingConfigController::class, 'index'])->name('index');
                    Route::post('/', [LogStreamingConfigController::class, 'store'])->name('store');
                    Route::get('{logStreamingConfig}', [LogStreamingConfigController::class, 'show'])->name('show');
                    Route::put('{logStreamingConfig}', [LogStreamingConfigController::class, 'update'])->name('update');
                    Route::delete('{logStreamingConfig}', [LogStreamingConfigController::class, 'destroy'])->name('destroy');
                });

                // ── Git Sync ────────────────────────────────────────

                Route::prefix('git-sync')->as('git-sync.')->group(function () {
                    Route::get('status', [GitSyncController::class, 'status'])->name('status');
                    Route::post('export', [GitSyncController::class, 'export'])->name('export');
                    Route::post('import', [GitSyncController::class, 'import'])->name('import');
                });

                // ── Shared Workflow Clone ────────────────────────────

                Route::post('shared/{token}/clone', [WorkflowShareController::class, 'clonePublic'])->name('shared.clone');

                // ── Templates (use within workspace) ────────────────

                Route::post('templates/{workflowTemplate}/use', [WorkflowTemplateController::class, 'use'])->name('templates.use');
            });

        /*
        |------------------------------------------------------------------
        | Global Catalogs — Authenticated but not workspace-scoped
        |------------------------------------------------------------------
        |
        | These are read-only catalogs that any authenticated user can
        | browse. They don't belong to any workspace.
        |
        */

        // ── Node Types ───────────────────────────────────────────────

        Route::prefix('nodes')->as('nodes.')->group(function () {
            Route::get('/', [NodeController::class, 'index'])->name('index');
            Route::get('{node}', [NodeController::class, 'show'])->name('show');
        });

        Route::prefix('node-categories')->as('node-categories.')->group(function () {
            Route::get('/', [NodeCategoryController::class, 'index'])->name('index');
            Route::get('{nodeCategory}', [NodeCategoryController::class, 'show'])->name('show');
        });

        // ── Credential Types ─────────────────────────────────────────

        Route::prefix('credential-types')->as('credential-types.')->group(function () {
            Route::get('/', [CredentialTypeController::class, 'index'])->name('index');
            Route::get('{credentialType}', [CredentialTypeController::class, 'show'])->name('show');
        });

        // ── Workflow Templates (global catalog) ─────────────────────

        Route::prefix('templates')->as('templates.')->group(function () {
            Route::get('/', [WorkflowTemplateController::class, 'index'])->name('index');
            Route::get('{workflowTemplate}', [WorkflowTemplateController::class, 'show'])->name('show');
        });
    });
});

<?php

use App\Enums\ExecutionStatus;
use App\Http\Resources\Api\V1\WebhookResource;
use App\Models\Execution;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Illuminate\Http\Request;

test('removed external engine endpoints return not found', function () {
    $this->postJson('/api/v1/jobs/callback', [])->assertNotFound();
    $this->postJson('/api/v1/jobs/progress', [])->assertNotFound();
    $this->postJson('/api/v1/internal/credentials', [])->assertNotFound();
    $this->postJson('/api/v1/internal/workflows/definition', [])->assertNotFound();
    $this->getJson('/api/v1/workspaces/1/engine/health')->assertNotFound();
    $this->postJson('/api/v1/workspaces/1/executions/1/pause-engine')->assertNotFound();
});

test('execution cancellation no longer depends on legacy job status tracking', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);

    $execution = Execution::factory()->running()->create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'triggered_by' => $user->id,
    ]);

    $cancelledExecution = app(ExecutionService::class)->cancel($execution);

    expect($cancelledExecution->status)->toBe(ExecutionStatus::Cancelled)
        ->and($cancelledExecution->finished_at)->not->toBeNull();
});

test('webhook resources use the native application callback url', function () {
    config(['app.url' => 'https://linkflow.test']);

    $webhook = new Webhook([
        'workflow_id' => 10,
        'workspace_id' => 20,
        'uuid' => 'webhook-uuid',
        'methods' => ['POST'],
        'is_active' => true,
        'auth_type' => 'none',
        'response_mode' => 'immediate',
        'response_status' => 200,
    ]);

    $payload = (new WebhookResource($webhook))->toArray(Request::create('/'));

    expect($payload['url'])->toBe('https://linkflow.test/api/v1/webhook/webhook-uuid');
});

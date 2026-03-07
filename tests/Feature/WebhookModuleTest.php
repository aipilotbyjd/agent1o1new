<?php

use App\Enums\ExecutionMode;
use App\Enums\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupWebhookWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'is_active' => true,
    ]);

    $version = WorkflowVersion::factory()->published()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $owner->id,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    return [$owner, $workspace, $workflow];
}

function createWebhook(Workspace $workspace, Workflow $workflow, array $overrides = []): Webhook
{
    return Webhook::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        ...$overrides,
    ]);
}

// ── CRUD ─────────────────────────────────────────────────────

test('owner can create a webhook for a workflow', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/webhook", [
            'methods' => ['POST', 'GET'],
            'auth_type' => 'bearer',
            'auth_config' => ['token' => 'my-secret-token'],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.workflow_id', $workflow->id)
        ->assertJsonPath('data.methods', ['POST', 'GET'])
        ->assertJsonPath('data.auth_type', 'bearer');

    $this->assertDatabaseHas('webhooks', [
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
    ]);
});

test('cannot create duplicate webhook for same workflow', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    createWebhook($workspace, $workflow);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/webhook");

    $response->assertStatus(409);
});

test('owner can list webhooks', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $workflow2 = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    createWebhook($workspace, $workflow);
    createWebhook($workspace, $workflow2);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/webhooks");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter webhooks by workflow_id', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $workflow2 = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    createWebhook($workspace, $workflow);
    createWebhook($workspace, $workflow2);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/webhooks?workflow_id={$workflow->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('can filter webhooks by active status', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $workflow2 = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    createWebhook($workspace, $workflow);
    createWebhook($workspace, $workflow2, ['is_active' => false]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/webhooks?is_active=true");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('owner can view a webhook', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/webhooks/{$webhook->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $webhook->id)
        ->assertJsonPath('data.uuid', $webhook->uuid)
        ->assertJsonStructure(['data' => ['url']]);
});

test('owner can update a webhook', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/webhooks/{$webhook->id}", [
            'methods' => ['POST', 'PUT'],
            'rate_limit' => 100,
            'is_active' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.methods', ['POST', 'PUT'])
        ->assertJsonPath('data.rate_limit', 100)
        ->assertJsonPath('data.is_active', false);
});

test('owner can delete a webhook', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/webhooks/{$webhook->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
});

// ── Authorization ────────────────────────────────────────────

test('viewer cannot create a webhook', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/webhook");

    $response->assertStatus(403);
});

test('viewer can view webhooks', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createWebhook($workspace, $workflow);

    $response = $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/webhooks");

    $response->assertOk();
});

test('non-member cannot access webhooks', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/webhooks");

    $response->assertStatus(403);
});

// ── Public Webhook Receiver ──────────────────────────────────

test('public webhook endpoint triggers execution', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow);

    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", [
        'event' => 'order.created',
        'data' => ['id' => 123],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('executions', [
        'workflow_id' => $workflow->id,
        'mode' => ExecutionMode::Webhook->value,
    ]);

    expect($webhook->fresh()->call_count)->toBe(1);
    expect($webhook->fresh()->last_called_at)->not->toBeNull();
});

test('public webhook with invalid uuid returns 404', function () {
    $response = $this->postJson('/api/v1/webhook/nonexistent-uuid', ['data' => 'test']);

    $response->assertStatus(404);
});

test('inactive webhook returns 410', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow, ['is_active' => false]);

    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", ['data' => 'test']);

    $response->assertStatus(410);
});

test('webhook with wrong HTTP method returns 405', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow, ['methods' => ['POST']]);

    $response = $this->getJson("/api/v1/webhook/{$webhook->uuid}");

    $response->assertStatus(405);
});

test('webhook with bearer auth rejects unauthorized requests', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow, [
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'secret-token-123'],
    ]);

    // Without token
    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", ['data' => 'test']);
    $response->assertStatus(401);

    // With wrong token
    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", ['data' => 'test'], [
        'Authorization' => 'Bearer wrong-token',
    ]);
    $response->assertStatus(401);
});

test('webhook with bearer auth accepts authorized requests', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow, [
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'secret-token-123'],
    ]);

    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", ['data' => 'test'], [
        'Authorization' => 'Bearer secret-token-123',
    ]);

    $response->assertOk();
});

test('webhook for inactive workflow returns 410', function () {
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow);
    $workflow->update(['is_active' => false]);

    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", ['data' => 'test']);

    $response->assertStatus(410);
});

test('webhook includes custom response body when configured', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupWebhookWorkspace();
    $webhook = createWebhook($workspace, $workflow, [
        'response_status' => 202,
        'response_body' => ['message' => 'Accepted', 'queued' => true],
    ]);

    $response = $this->postJson("/api/v1/webhook/{$webhook->uuid}", ['data' => 'test']);

    $response->assertStatus(202)
        ->assertJsonPath('message', 'Accepted')
        ->assertJsonPath('queued', true);
});

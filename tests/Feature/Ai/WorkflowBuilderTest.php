<?php

use App\Ai\Agents\WorkflowBuilderAgent;
use App\Enums\Role;
use App\Models\AiGenerationLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

uses(RefreshDatabase::class);

function workspaceWithOwner(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

// ── Success ───────────────────────────────────────────────────

it('generates a workflow from a natural language description', function () {
    Ai::fakeAgent(WorkflowBuilderAgent::class, [
        [
            'workflow_name' => 'Webhook to Email',
            'workflow_description' => 'Receives a webhook and sends an email.',
            'nodes' => [
                ['key' => 'webhook_1', 'type' => 'trigger.webhook', 'name' => 'Webhook Trigger', 'config' => [], 'position' => ['x' => 0, 'y' => 0]],
                ['key' => 'mail_1', 'type' => 'app.mail.send', 'name' => 'Send Email', 'config' => ['to' => 'user@example.com'], 'position' => ['x' => 250, 'y' => 0]],
            ],
            'edges' => [
                ['source' => 'webhook_1', 'target' => 'mail_1', 'source_handle' => 'output', 'target_handle' => 'input'],
            ],
        ],
    ]);

    [$owner, $workspace] = workspaceWithOwner();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => 'When a webhook is received, send an email to the user.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Webhook to Email')
        ->assertJsonPath('data.description', 'Receives a webhook and sends an email.')
        ->assertJsonPath('data.current_version.version_number', 1)
        ->assertJsonPath('data.current_version.is_published', true)
        ->assertJsonCount(2, 'data.current_version.nodes')
        ->assertJsonCount(1, 'data.current_version.edges');

    $this->assertDatabaseHas('workflows', [
        'workspace_id' => $workspace->id,
        'name' => 'Webhook to Email',
        'created_by' => $owner->id,
        'is_active' => false,
    ]);

    $this->assertDatabaseCount('workflow_versions', 1);

    $version = WorkflowVersion::first();
    expect($version->version_number)->toBe(1)
        ->and($version->is_published)->toBeTrue()
        ->and($version->nodes)->toHaveCount(2)
        ->and($version->edges)->toHaveCount(1);
});

it('creates an ai_generation_log entry on success', function () {
    Ai::fakeAgent(WorkflowBuilderAgent::class, [
        [
            'workflow_name' => 'Scheduled Report',
            'workflow_description' => 'Runs on a schedule and sends a report.',
            'nodes' => [],
            'edges' => [],
        ],
    ]);

    [$owner, $workspace] = workspaceWithOwner();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => 'Run a report every Monday and email the results to the team.',
        ]);

    $log = AiGenerationLog::query()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $owner->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->prompt)->toBe('Run a report every Monday and email the results to the team.')
        ->and($log->status)->toBe('draft')
        ->and($log->workflow_id)->not->toBeNull();
});

it('links the workflow_id to the generated workflow', function () {
    Ai::fakeAgent(WorkflowBuilderAgent::class, [
        [
            'workflow_name' => 'Linked Test',
            'workflow_description' => 'A linked test workflow.',
            'nodes' => [],
            'edges' => [],
        ],
    ]);

    [$owner, $workspace] = workspaceWithOwner();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => 'A simple test workflow to verify log linking.',
        ]);

    $workflowId = $response->json('data.id');
    $log = AiGenerationLog::query()->where('workflow_id', $workflowId)->first();

    expect($log)->not->toBeNull()
        ->and($log->workflow_id)->toBe($workflowId);
});

// ── Validation ────────────────────────────────────────────────

it('rejects an empty description', function () {
    [$owner, $workspace] = workspaceWithOwner();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => '',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);
});

it('rejects a description shorter than 10 characters', function () {
    [$owner, $workspace] = workspaceWithOwner();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => 'Too short',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);
});

it('rejects a description longer than 2000 characters', function () {
    [$owner, $workspace] = workspaceWithOwner();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => str_repeat('a', 2001),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);
});

// ── Authorization ─────────────────────────────────────────────

it('returns 403 when viewer calls the build endpoint', function () {
    [$owner, $workspace] = workspaceWithOwner();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => 'Send a Slack message when a webhook fires.',
        ])
        ->assertStatus(403);
});

it('returns 401 for unauthenticated requests', function () {
    [, $workspace] = workspaceWithOwner();

    $this->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
        'description' => 'Send a Slack message when a webhook fires.',
    ])
        ->assertStatus(401);
});

it('returns 403 for non-members', function () {
    [, $workspace] = workspaceWithOwner();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", [
            'description' => 'Send a Slack message when a webhook fires.',
        ])
        ->assertStatus(403);
});

// ── No duplicate workflows on identical prompts ───────────────

it('creates a fresh workflow each time even for identical descriptions', function () {
    Ai::fakeAgent(WorkflowBuilderAgent::class, [
        ['workflow_name' => 'Same Workflow', 'workflow_description' => 'Same.', 'nodes' => [], 'edges' => []],
        ['workflow_name' => 'Same Workflow', 'workflow_description' => 'Same.', 'nodes' => [], 'edges' => []],
    ]);

    [$owner, $workspace] = workspaceWithOwner();

    $payload = ['description' => 'Identical description for both requests.'];

    $this->actingAs($owner, 'api')->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", $payload)->assertStatus(201);
    $this->actingAs($owner, 'api')->postJson("/api/v1/workspaces/{$workspace->id}/workflows/build", $payload)->assertStatus(201);

    expect(Workflow::query()->where('workspace_id', $workspace->id)->count())->toBe(2);
});

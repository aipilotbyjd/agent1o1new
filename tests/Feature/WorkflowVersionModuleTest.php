<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupWorkspaceAndWorkflow(): array
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
    ]);

    return [$owner, $workspace, $workflow];
}

function createVersion(Workflow $workflow, User $creator, array $overrides = []): WorkflowVersion
{
    $nextNumber = (int) $workflow->versions()->max('version_number') + 1;

    return WorkflowVersion::factory()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $creator->id,
        'version_number' => $nextNumber,
        ...$overrides,
    ]);
}

function validVersionPayload(array $overrides = []): array
{
    return [
        'nodes' => [
            ['id' => 'node_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Start']],
        ],
        'edges' => [],
        'change_summary' => 'Initial version',
        ...$overrides,
    ];
}

// ── Create Version ──────────────────────────────────────────

test('owner can create a workflow version', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions", validVersionPayload());

    $response->assertStatus(201)
        ->assertJsonPath('data.version_number', 1);

    expect($response->json('data.is_published'))->toBeFalsy();

    $this->assertDatabaseHas('workflow_versions', [
        'workflow_id' => $workflow->id,
        'version_number' => 1,
    ]);
});

test('version number auto-increments', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    createVersion($workflow, $owner, ['version_number' => 1]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions", validVersionPayload());

    $response->assertStatus(201)
        ->assertJsonPath('data.version_number', 2);
});

test('creating version with missing nodes fails validation', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions", [
            'edges' => [],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('nodes');
});

test('creating version on locked workflow fails', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $workflow->update(['is_locked' => true]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions", validVersionPayload());

    $response->assertStatus(423);
});

// ── List Versions ───────────────────────────────────────────

test('owner can list workflow versions', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    createVersion($workflow, $owner, ['version_number' => 1]);
    createVersion($workflow, $owner, ['version_number' => 2]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions");

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version_number', 2);
});

// ── Show Version ────────────────────────────────────────────

test('owner can view a single version', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $version = createVersion($workflow, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$version->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonStructure(['data' => ['nodes', 'edges']]);
});

// ── Publish ─────────────────────────────────────────────────

test('owner can publish a version', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $version = createVersion($workflow, $owner);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$version->id}/publish");

    $response->assertOk()
        ->assertJsonPath('data.is_published', true);

    expect($workflow->fresh()->current_version_id)->toBe($version->id);
});

test('publishing sets workflow current_version_id', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $v1 = createVersion($workflow, $owner, ['version_number' => 1]);
    $v2 = createVersion($workflow, $owner, ['version_number' => 2]);

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$v1->id}/publish")
        ->assertOk();

    expect($workflow->fresh()->current_version_id)->toBe($v1->id);

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$v2->id}/publish")
        ->assertOk();

    expect($workflow->fresh()->current_version_id)->toBe($v2->id);
});

test('publishing already-published version returns conflict', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $version = createVersion($workflow, $owner, ['is_published' => true, 'published_at' => now()]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$version->id}/publish");

    $response->assertStatus(409);
});

test('publishing on locked workflow fails', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $version = createVersion($workflow, $owner);
    $workflow->update(['is_locked' => true]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$version->id}/publish");

    $response->assertStatus(423);
});

// ── Rollback ────────────────────────────────────────────────

test('owner can rollback to a previous version', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $v1 = createVersion($workflow, $owner, [
        'version_number' => 1,
        'nodes' => [['id' => 'n1', 'type' => 'trigger', 'data' => []]],
    ]);
    createVersion($workflow, $owner, [
        'version_number' => 2,
        'nodes' => [['id' => 'n1', 'type' => 'trigger', 'data' => []], ['id' => 'n2', 'type' => 'http', 'data' => []]],
    ]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$v1->id}/rollback");

    $response->assertStatus(201)
        ->assertJsonPath('data.version_number', 3)
        ->assertJsonPath('data.is_published', true)
        ->assertJsonPath('data.change_summary', 'Rolled back to version 1');

    expect($workflow->fresh()->current_version_id)->toBe($response->json('data.id'));
});

test('rollback creates a new version not mutation', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $v1 = createVersion($workflow, $owner, ['version_number' => 1]);

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/{$v1->id}/rollback")
        ->assertStatus(201);

    expect($workflow->versions()->count())->toBe(2);
    expect($v1->fresh()->version_number)->toBe(1);
});

// ── Diff ────────────────────────────────────────────────────

test('can diff two versions', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $v1 = createVersion($workflow, $owner, [
        'version_number' => 1,
        'nodes' => [
            ['id' => 'n1', 'type' => 'trigger', 'data' => ['label' => 'Start']],
            ['id' => 'n2', 'type' => 'http', 'data' => ['url' => 'https://old.com']],
        ],
    ]);
    $v2 = createVersion($workflow, $owner, [
        'version_number' => 2,
        'nodes' => [
            ['id' => 'n1', 'type' => 'trigger', 'data' => ['label' => 'Start']],
            ['id' => 'n3', 'type' => 'email', 'data' => []],
        ],
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions/diff?from={$v1->id}&to={$v2->id}");

    $response->assertOk();
    $data = $response->json('data');

    expect($data['added'])->toHaveCount(1);
    expect($data['added'][0]['id'])->toBe('n3');
    expect($data['removed'])->toHaveCount(1);
    expect($data['removed'][0]['id'])->toBe('n2');
});

// ── Authorization ───────────────────────────────────────────

test('viewer can view versions', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createVersion($workflow, $owner);

    $response = $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions");

    $response->assertOk();
});

test('viewer cannot create a version', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions", validVersionPayload());

    $response->assertStatus(403);
});

test('non-member cannot access versions', function () {
    [$owner, $workspace, $workflow] = setupWorkspaceAndWorkflow();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/versions");

    $response->assertStatus(403);
});

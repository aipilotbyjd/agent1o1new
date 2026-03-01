<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWorkspaceWithOwner(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

function createWorkflow(Workspace $workspace, User $owner, array $overrides = []): Workflow
{
    return Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        ...$overrides,
    ]);
}

// ── CRUD ─────────────────────────────────────────────────────

test('owner can create a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows", [
            'name' => 'My Workflow',
            'description' => 'Does something cool',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'My Workflow');

    $this->assertDatabaseHas('workflows', [
        'name' => 'My Workflow',
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
});

test('owner can list workflows', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    createWorkflow($workspace, $owner);
    createWorkflow($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('owner can view a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $workflow->id);
});

test('owner can update a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}", [
            'name' => 'Updated Workflow',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Workflow');
});

test('owner can delete a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}");

    $response->assertOk();
    $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);
});

// ── Activate / Deactivate ────────────────────────────────────

test('owner can activate a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/activate");

    $response->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('owner can deactivate a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner, ['is_active' => true]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/deactivate");

    $response->assertOk()
        ->assertJsonPath('data.is_active', false);
});

// ── Duplicate ────────────────────────────────────────────────

test('owner can duplicate a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner, ['name' => 'Original']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/duplicate");

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Original (Copy)')
        ->assertJsonPath('data.is_active', false);
});

// ── Locked workflows ─────────────────────────────────────────

test('locked workflow cannot be updated', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner, ['is_locked' => true]);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}", [
            'name' => 'Hacked',
        ]);

    $response->assertStatus(423);
});

test('locked workflow cannot be deleted', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $workflow = createWorkflow($workspace, $owner, ['is_locked' => true]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}");

    $response->assertStatus(423);
});

// ── Authorization ────────────────────────────────────────────

test('viewer cannot create a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows", [
            'name' => 'Not Allowed',
        ]);

    $response->assertStatus(403);
});

test('viewer cannot delete a workflow', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    $workflow = createWorkflow($workspace, $owner);

    $response = $this->actingAs($viewer, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}");

    $response->assertStatus(403);
});

test('non-member cannot access workspace workflows', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows");

    $response->assertStatus(403);
});

test('workflow from different workspace returns 404', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    $otherWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workflow = createWorkflow($otherWorkspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}");

    $response->assertStatus(404);
});

// ── Search & Filter ──────────────────────────────────────────

test('can search workflows by name', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    createWorkflow($workspace, $owner, ['name' => 'Email Automation']);
    createWorkflow($workspace, $owner, ['name' => 'Slack Bot']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows?search=Email");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Email Automation');
});

test('can filter workflows by active status', function () {
    [$owner, $workspace] = createWorkspaceWithOwner();
    createWorkflow($workspace, $owner, ['is_active' => true]);
    createWorkflow($workspace, $owner, ['is_active' => false]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows?is_active=true");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

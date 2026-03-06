<?php

use App\Enums\Role;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupTagWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

function createTag(Workspace $workspace, array $overrides = []): Tag
{
    return Tag::factory()->create([
        'workspace_id' => $workspace->id,
        ...$overrides,
    ]);
}

// ── CRUD ─────────────────────────────────────────────────────

test('owner can create a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/tags", [
            'name' => 'Production',
            'color' => '#ff5733',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Production')
        ->assertJsonPath('data.color', '#ff5733');

    $this->assertDatabaseHas('tags', [
        'workspace_id' => $workspace->id,
        'name' => 'Production',
    ]);
});

test('owner can list tags', function () {
    [$owner, $workspace] = setupTagWorkspace();
    createTag($workspace, ['name' => 'Alpha']);
    createTag($workspace, ['name' => 'Beta']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/tags");

    $response->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

test('tags index includes workflows count', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace);
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
    $tag->workflows()->attach($workflow->id);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/tags");

    $response->assertSuccessful()
        ->assertJsonPath('data.0.workflows_count', 1);
});

test('can search tags by name', function () {
    [$owner, $workspace] = setupTagWorkspace();
    createTag($workspace, ['name' => 'Production']);
    createTag($workspace, ['name' => 'Staging']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/tags?search=Prod");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Production');
});

test('owner can view a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace, ['name' => 'Important']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}");

    $response->assertSuccessful()
        ->assertJsonPath('data.name', 'Important');
});

test('owner can update a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}", [
            'name' => 'Renamed',
            'color' => '#00ff00',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.color', '#00ff00');
});

test('owner can delete a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}");

    $response->assertSuccessful();
    $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
});

// ── Attach / Detach Workflows ───────────────────────────────

test('owner can attach workflows to a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace);
    $workflow1 = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    $workflow2 = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}/workflows", [
            'workflow_ids' => [$workflow1->id, $workflow2->id],
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.workflows_count', 2);

    $this->assertDatabaseHas('workflow_tags', ['tag_id' => $tag->id, 'workflow_id' => $workflow1->id]);
    $this->assertDatabaseHas('workflow_tags', ['tag_id' => $tag->id, 'workflow_id' => $workflow2->id]);
});

test('attaching same workflow twice does not duplicate', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace);
    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);

    $tag->workflows()->attach($workflow->id);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}/workflows", [
            'workflow_ids' => [$workflow->id],
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.workflows_count', 1);
});

test('owner can detach workflows from a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $tag = createTag($workspace);
    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    $tag->workflows()->attach($workflow->id);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}/workflows", [
            'workflow_ids' => [$workflow->id],
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.workflows_count', 0);

    $this->assertDatabaseMissing('workflow_tags', ['tag_id' => $tag->id, 'workflow_id' => $workflow->id]);
});

// ── Validation ──────────────────────────────────────────────

test('duplicate name in same workspace fails validation', function () {
    [$owner, $workspace] = setupTagWorkspace();
    createTag($workspace, ['name' => 'Duplicate']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/tags", [
            'name' => 'Duplicate',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

// ── Authorization ───────────────────────────────────────────

test('viewer cannot create a tag', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/tags", [
            'name' => 'Not Allowed',
        ]);

    $response->assertForbidden();
});

test('viewer can view tags', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createTag($workspace);

    $response = $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/tags");

    $response->assertSuccessful();
});

test('non-member cannot access tags', function () {
    [$owner, $workspace] = setupTagWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/tags");

    $response->assertForbidden();
});

<?php

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function createOwnerWithWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

// ── Workspace CRUD ───────────────────────────────────────────

test('authenticated user can create a workspace', function () {
    $user = User::factory()->create();

    // Create the free plan required for workspace bootstrap
    \App\Models\Plan::factory()->free()->create();

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/v1/workspaces', ['name' => 'My Workspace']);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'My Workspace');

    $this->assertDatabaseHas('workspaces', ['name' => 'My Workspace', 'owner_id' => $user->id]);
    $this->assertDatabaseHas('workspace_members', ['user_id' => $user->id, 'role' => 'owner']);
});

test('authenticated user can list their workspaces', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->getJson('/api/v1/workspaces');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $workspace->id);
});

test('owner can view workspace details', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}");

    $response->assertOk()
        ->assertJsonPath('data.name', $workspace->name);
});

test('owner can update workspace', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Updated']);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated');
});

test('owner can delete workspace', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
});

test('non-member cannot access workspace', function () {
    [, $workspace] = createOwnerWithWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}");

    $response->assertStatus(403);
});

test('viewer cannot update workspace', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Hacked']);

    $response->assertStatus(403);
});

// ── Members ──────────────────────────────────────────────────

test('owner can list workspace members', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/members");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('owner can update a member role', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => Role::Member->value, 'joined_at' => now()]);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/members/{$member->id}", ['role' => 'admin']);

    $response->assertOk();
    $this->assertDatabaseHas('workspace_members', [
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'admin',
    ]);
});

test('owner can remove a member', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => Role::Member->value, 'joined_at' => now()]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$member->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('workspace_members', ['user_id' => $member->id, 'workspace_id' => $workspace->id]);
});

test('member can leave a workspace', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => Role::Member->value, 'joined_at' => now()]);

    $response = $this->actingAs($member, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/leave");

    $response->assertOk();
    $this->assertDatabaseMissing('workspace_members', ['user_id' => $member->id, 'workspace_id' => $workspace->id]);
});

test('owner cannot leave workspace', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/leave");

    $response->assertStatus(403);
});

// ── Invitations ──────────────────────────────────────────────

test('owner can send an invitation', function () {
    Notification::fake();
    [$owner, $workspace] = createOwnerWithWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'newuser@example.com',
            'role' => 'member',
        ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('invitations', ['email' => 'newuser@example.com', 'workspace_id' => $workspace->id]);
});

test('owner can list pending invitations', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/invitations");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('owner can cancel an invitation', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
});

test('user can accept an invitation', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $user = User::factory()->create();
    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => $user->email,
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/v1/invitations/{$invitation->token}/accept");

    $response->assertOk();
    $this->assertDatabaseHas('workspace_members', ['user_id' => $user->id, 'workspace_id' => $workspace->id]);
    $this->assertNotNull($invitation->fresh()->accepted_at);
});

test('user can decline an invitation', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $user = User::factory()->create();
    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => $user->email,
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/v1/invitations/{$invitation->token}/decline");

    $response->assertOk();
    $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
});

test('user cannot accept invitation sent to different email', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $user = User::factory()->create();
    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => 'other@example.com',
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/v1/invitations/{$invitation->token}/accept");

    $response->assertStatus(403);
});

test('expired invitation cannot be accepted', function () {
    [$owner, $workspace] = createOwnerWithWorkspace();
    $user = User::factory()->create();
    $invitation = Invitation::factory()->expired()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => $user->email,
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/v1/invitations/{$invitation->token}/accept");

    $response->assertStatus(422);
});

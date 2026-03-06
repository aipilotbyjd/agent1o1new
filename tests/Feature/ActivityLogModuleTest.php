<?php

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupActivityLogWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

function createActivityLog(Workspace $workspace, User $user, array $overrides = []): ActivityLog
{
    return ActivityLog::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        ...$overrides,
    ]);
}

// ── List ─────────────────────────────────────────────────────

test('owner can list activity logs', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    createActivityLog($workspace, $owner);
    createActivityLog($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs");

    $response->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

test('activity logs are ordered by created_at desc', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    $older = createActivityLog($workspace, $owner, [
        'action' => 'older_action',
        'created_at' => now()->subHour(),
    ]);
    $newer = createActivityLog($workspace, $owner, [
        'action' => 'newer_action',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs");

    $response->assertSuccessful();
    expect($response->json('data.0.action'))->toBe('newer_action');
    expect($response->json('data.1.action'))->toBe('older_action');
});

// ── Filters ─────────────────────────────────────────────────

test('can filter activity logs by action', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    createActivityLog($workspace, $owner, ['action' => 'created']);
    createActivityLog($workspace, $owner, ['action' => 'deleted']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs?action=created");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'created');
});

test('can filter activity logs by user_id', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    $otherUser = User::factory()->create();
    $workspace->members()->attach($otherUser->id, ['role' => Role::Member->value, 'joined_at' => now()]);

    createActivityLog($workspace, $owner, ['action' => 'owner_action']);
    createActivityLog($workspace, $otherUser, ['action' => 'other_action']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs?user_id={$otherUser->id}");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'other_action');
});

test('can filter activity logs by subject_type', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    createActivityLog($workspace, $owner, ['subject_type' => 'App\\Models\\Workflow', 'subject_id' => 1]);
    createActivityLog($workspace, $owner, ['subject_type' => 'App\\Models\\Variable', 'subject_id' => 1]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs?subject_type=App%5CModels%5CWorkflow");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

test('can filter activity logs by date range', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    createActivityLog($workspace, $owner, ['action' => 'old', 'created_at' => now()->subDays(10)]);
    createActivityLog($workspace, $owner, ['action' => 'recent', 'created_at' => now()->subDay()]);

    $from = now()->subDays(3)->toDateTimeString();
    $to = now()->toDateTimeString();

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs?from={$from}&to={$to}");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'recent');
});

test('can search activity logs by description', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    createActivityLog($workspace, $owner, ['description' => 'Created workflow Pipeline']);
    createActivityLog($workspace, $owner, ['description' => 'Deleted variable DB_HOST']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs?search=Pipeline");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

// ── Show ─────────────────────────────────────────────────────

test('owner can view a single activity log', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    $log = createActivityLog($workspace, $owner, ['action' => 'test_action']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs/{$log->id}");

    $response->assertSuccessful()
        ->assertJsonPath('data.action', 'test_action')
        ->assertJsonStructure(['data' => ['id', 'user', 'action', 'description', 'created_at']]);
});

// ── Authorization ───────────────────────────────────────────

test('viewer can view activity logs', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createActivityLog($workspace, $owner);

    $response = $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs");

    $response->assertSuccessful();
});

test('non-member cannot access activity logs', function () {
    [$owner, $workspace] = setupActivityLogWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/activity-logs");

    $response->assertForbidden();
});

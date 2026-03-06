<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\Variable;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupVariableWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

function createVariable(Workspace $workspace, User $owner, array $overrides = []): Variable
{
    return Variable::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        ...$overrides,
    ]);
}

// ── CRUD ─────────────────────────────────────────────────────

test('owner can create a variable', function () {
    [$owner, $workspace] = setupVariableWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/variables", [
            'key' => 'API_URL',
            'value' => 'https://example.com',
            'description' => 'The API base URL',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.key', 'API_URL')
        ->assertJsonPath('data.value', 'https://example.com');

    $this->assertDatabaseHas('variables', [
        'workspace_id' => $workspace->id,
        'key' => 'API_URL',
    ]);
});

test('owner can list variables', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    createVariable($workspace, $owner, ['key' => 'VAR_ONE']);
    createVariable($workspace, $owner, ['key' => 'VAR_TWO']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables");

    $response->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

test('can search variables by key', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    createVariable($workspace, $owner, ['key' => 'DATABASE_URL']);
    createVariable($workspace, $owner, ['key' => 'APP_SECRET']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables?search=DATABASE");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'DATABASE_URL');
});

test('can filter variables by is_secret', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    createVariable($workspace, $owner, ['key' => 'PUBLIC_VAR', 'is_secret' => false]);
    createVariable($workspace, $owner, ['key' => 'SECRET_VAR', 'is_secret' => true]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables?is_secret=true");

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'SECRET_VAR');
});

test('owner can view a variable', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $variable = createVariable($workspace, $owner, ['key' => 'MY_VAR']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables/{$variable->id}");

    $response->assertSuccessful()
        ->assertJsonPath('data.key', 'MY_VAR');
});

test('owner can update a variable', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $variable = createVariable($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/variables/{$variable->id}", [
            'key' => 'UPDATED_KEY',
            'value' => 'new-value',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.key', 'UPDATED_KEY')
        ->assertJsonPath('data.value', 'new-value');
});

test('owner can delete a variable', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $variable = createVariable($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/variables/{$variable->id}");

    $response->assertSuccessful();
    $this->assertDatabaseMissing('variables', ['id' => $variable->id]);
});

// ── Secret Masking ──────────────────────────────────────────

test('secret variable value is masked in response', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $variable = createVariable($workspace, $owner, [
        'key' => 'SECRET_KEY',
        'value' => 'super-secret-value',
        'is_secret' => true,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables/{$variable->id}");

    $response->assertSuccessful()
        ->assertJsonPath('data.value', '********')
        ->assertJsonPath('data.is_secret', true);
});

test('non-secret variable value is visible in response', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $variable = createVariable($workspace, $owner, [
        'key' => 'PUBLIC_KEY',
        'value' => 'visible-value',
        'is_secret' => false,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables/{$variable->id}");

    $response->assertSuccessful()
        ->assertJsonPath('data.value', 'visible-value');
});

// ── Validation ──────────────────────────────────────────────

test('duplicate key in same workspace fails validation', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    createVariable($workspace, $owner, ['key' => 'DUPLICATE_KEY']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/variables", [
            'key' => 'DUPLICATE_KEY',
            'value' => 'some-value',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('key');
});

test('same key in different workspaces is allowed', function () {
    [$owner, $workspace1] = setupVariableWorkspace();

    $workspace2 = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace2->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    createVariable($workspace1, $owner, ['key' => 'SHARED_KEY']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace2->id}/variables", [
            'key' => 'SHARED_KEY',
            'value' => 'different-value',
        ]);

    $response->assertCreated();
});

// ── Authorization ───────────────────────────────────────────

test('viewer cannot create a variable', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/variables", [
            'key' => 'NOT_ALLOWED',
            'value' => 'nope',
        ]);

    $response->assertForbidden();
});

test('viewer can view variables', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createVariable($workspace, $owner);

    $response = $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables");

    $response->assertSuccessful();
});

test('non-member cannot access variables', function () {
    [$owner, $workspace] = setupVariableWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/variables");

    $response->assertForbidden();
});

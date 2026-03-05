<?php

use App\Enums\Role;
use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupCredentialWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    return [$owner, $workspace];
}

function createCredential(Workspace $workspace, User $owner, array $overrides = []): Credential
{
    return Credential::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        ...$overrides,
    ]);
}

// ── CRUD ─────────────────────────────────────────────────────

test('owner can create a credential', function () {
    [$owner, $workspace] = setupCredentialWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/credentials", [
            'name' => 'My API Key',
            'type' => 'http_bearer',
            'data' => ['token' => 'sk-secret-123'],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'My API Key')
        ->assertJsonPath('data.type', 'http_bearer');

    $this->assertDatabaseHas('credentials', [
        'workspace_id' => $workspace->id,
        'name' => 'My API Key',
        'type' => 'http_bearer',
    ]);
});

test('credential data is encrypted in database', function () {
    [$owner, $workspace] = setupCredentialWorkspace();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/credentials", [
            'name' => 'Encrypted Cred',
            'type' => 'http_basic',
            'data' => ['username' => 'admin', 'password' => 'secret'],
        ])
        ->assertStatus(201);

    $credential = Credential::query()->where('name', 'Encrypted Cred')->first();

    // Raw DB value should not contain the plain text
    $rawData = $credential->getRawOriginal('data');
    expect($rawData)->not->toContain('admin');
    expect($rawData)->not->toContain('secret');
});

test('credential data is not exposed in response', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $credential = createCredential($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credentials/{$credential->id}");

    $response->assertOk();
    expect($response->json('data'))->not->toHaveKey('data');
});

test('owner can list credentials', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    createCredential($workspace, $owner);
    createCredential($workspace, $owner, ['name' => 'Second Cred']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credentials");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can search credentials by name', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    createCredential($workspace, $owner, ['name' => 'Slack Token']);
    createCredential($workspace, $owner, ['name' => 'GitHub PAT']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credentials?search=Slack");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Slack Token');
});

test('can filter credentials by type', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    createCredential($workspace, $owner, ['type' => 'oauth2']);
    createCredential($workspace, $owner, ['name' => 'Basic', 'type' => 'http_basic']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credentials?type=oauth2");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('owner can update a credential name', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $credential = createCredential($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/credentials/{$credential->id}", [
            'name' => 'Renamed Credential',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Renamed Credential');
});

test('owner can update credential data', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $credential = createCredential($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->putJson("/api/v1/workspaces/{$workspace->id}/credentials/{$credential->id}", [
            'data' => ['api_key' => 'new-key-456'],
        ]);

    $response->assertOk();

    $updated = $credential->fresh();
    $decoded = json_decode($updated->data, true);
    expect($decoded)->toHaveKey('api_key', 'new-key-456');
});

test('owner can delete a credential', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $credential = createCredential($workspace, $owner);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/credentials/{$credential->id}");

    $response->assertOk();
    $this->assertSoftDeleted('credentials', ['id' => $credential->id]);
});

test('duplicate name in same workspace fails validation', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    createCredential($workspace, $owner, ['name' => 'Duplicate']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/credentials", [
            'name' => 'Duplicate',
            'type' => 'http_basic',
            'data' => ['key' => 'value'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// ── Test Connection ──────────────────────────────────────────

test('owner can test a credential with valid data', function () {
    [$owner, $workspace] = setupCredentialWorkspace();

    CredentialType::factory()->create([
        'type' => 'http_bearer',
        'fields_schema' => [
            'properties' => ['token' => ['type' => 'string']],
            'required' => ['token'],
        ],
    ]);

    $credential = createCredential($workspace, $owner, [
        'type' => 'http_bearer',
        'data' => json_encode(['token' => 'sk-valid-token']),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/credentials/{$credential->id}/test");

    $response->assertOk()
        ->assertJsonPath('data.success', true);
});

test('test credential with missing required fields fails', function () {
    [$owner, $workspace] = setupCredentialWorkspace();

    CredentialType::factory()->create([
        'type' => 'smtp',
        'fields_schema' => [
            'properties' => [
                'host' => ['type' => 'string'],
                'password' => ['type' => 'string'],
            ],
            'required' => ['host', 'password'],
        ],
    ]);

    $credential = createCredential($workspace, $owner, [
        'type' => 'smtp',
        'data' => json_encode(['host' => 'mail.example.com']),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/credentials/{$credential->id}/test");

    $response->assertStatus(422)
        ->assertJsonPath('data.success', false);
});

// ── Authorization ────────────────────────────────────────────

test('viewer cannot create a credential', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/credentials", [
            'name' => 'Not Allowed',
            'type' => 'http_basic',
            'data' => ['key' => 'value'],
        ]);

    $response->assertStatus(403);
});

test('viewer can view credentials', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createCredential($workspace, $owner);

    $response = $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credentials");

    $response->assertOk();
});

test('non-member cannot access credentials', function () {
    [$owner, $workspace] = setupCredentialWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credentials");

    $response->assertStatus(403);
});

// ── Credential Types (Global Catalog) ────────────────────────

test('authenticated user can list credential types', function () {
    $user = User::factory()->create();
    CredentialType::factory()->count(3)->create();

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/credential-types');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('can view a credential type with fields schema', function () {
    $user = User::factory()->create();
    $type = CredentialType::factory()->create([
        'fields_schema' => [
            'properties' => [
                'api_key' => ['type' => 'string', 'secret' => true],
                'base_url' => ['type' => 'string'],
            ],
            'required' => ['api_key'],
        ],
    ]);

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/v1/credential-types/{$type->id}");

    $response->assertOk()
        ->assertJsonPath('data.type', $type->type)
        ->assertJsonStructure(['data' => ['fields_schema']]);
});

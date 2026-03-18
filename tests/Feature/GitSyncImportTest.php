<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($this->user, 'api');
});

it('imports workflows via git sync', function () {
    $payload = [
        'workflows' => [
            'my-workflow-1' => [
                'format_version' => '1.0',
                'exported_at' => now()->toIso8601String(),
                'workflow' => [
                    'name' => 'Imported from Git',
                    'description' => 'A test workflow',
                    'icon' => 'lucide:zap',
                    'color' => '#ffffff',
                ],
                'version' => [
                    'nodes' => [['id' => 't1', 'type' => 'trigger', 'name' => 'Start', 'data' => [], 'position' => ['x' => 0, 'y' => 0]]],
                    'edges' => [],
                    'settings' => [],
                ],
                'tags' => [],
                'required_credentials' => [],
            ],
        ],
    ];

    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/git-sync/import",
        $payload,
    );

    $response->assertSuccessful()
        ->assertJsonPath('data.imported', 1)
        ->assertJsonPath('data.skipped', 0);

    $this->assertDatabaseHas('workflows', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Imported from Git',
    ]);

    $this->assertDatabaseHas('workspace_settings', [
        'workspace_id' => $this->workspace->id,
    ]);
});

it('rejects import with empty workflows', function () {
    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/git-sync/import",
        ['workflows' => []],
    );

    $response->assertUnprocessable();
});

it('rejects import without workflows key', function () {
    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/git-sync/import",
        ['data' => 'something'],
    );

    $response->assertUnprocessable();
});

it('handles webhook push with valid signature', function () {
    WorkspaceSetting::create([
        'workspace_id' => $this->workspace->id,
        'git_repo_url' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'git_auto_sync' => true,
        'git_sync_config' => ['webhook_secret' => 'test-secret-123'],
    ]);

    $payload = json_encode([
        'ref' => 'refs/heads/main',
        'workflows' => [
            'webhook-wf' => [
                'format_version' => '1.0',
                'workflow' => [
                    'name' => 'Webhook Imported',
                    'icon' => 'lucide:webhook',
                    'color' => '#000000',
                ],
                'version' => [
                    'nodes' => [['id' => 't1', 'type' => 'trigger', 'name' => 'Start', 'data' => [], 'position' => ['x' => 0, 'y' => 0]]],
                    'edges' => [],
                    'settings' => [],
                ],
                'tags' => [],
                'required_credentials' => [],
            ],
        ],
    ]);

    $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-secret-123');

    $response = $this->postJson(
        "/api/v1/git-sync/webhook/{$this->workspace->slug}",
        json_decode($payload, true),
        ['X-Hub-Signature-256' => $signature],
    );

    $response->assertSuccessful();

    $this->assertDatabaseHas('workflows', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Webhook Imported',
    ]);
});

it('rejects webhook with invalid signature', function () {
    WorkspaceSetting::create([
        'workspace_id' => $this->workspace->id,
        'git_repo_url' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'git_auto_sync' => true,
        'git_sync_config' => ['webhook_secret' => 'real-secret'],
    ]);

    $response = $this->postJson(
        "/api/v1/git-sync/webhook/{$this->workspace->slug}",
        ['ref' => 'refs/heads/main', 'workflows' => []],
        ['X-Hub-Signature-256' => 'sha256=invalid'],
    );

    $response->assertForbidden();
});

it('skips webhook push to non-target branch', function () {
    WorkspaceSetting::create([
        'workspace_id' => $this->workspace->id,
        'git_repo_url' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'git_auto_sync' => true,
        'git_sync_config' => ['webhook_secret' => 'secret'],
    ]);

    $payload = json_encode([
        'ref' => 'refs/heads/develop',
        'workflows' => [
            'wf' => [
                'format_version' => '1.0',
                'workflow' => ['name' => 'Should Not Import'],
                'version' => null,
                'tags' => [],
                'required_credentials' => [],
            ],
        ],
    ]);

    $signature = 'sha256='.hash_hmac('sha256', $payload, 'secret');

    $response = $this->postJson(
        "/api/v1/git-sync/webhook/{$this->workspace->slug}",
        json_decode($payload, true),
        ['X-Hub-Signature-256' => $signature],
    );

    $response->assertSuccessful()
        ->assertJsonPath('data.imported', 0);

    $this->assertDatabaseMissing('workflows', [
        'name' => 'Should Not Import',
    ]);
});

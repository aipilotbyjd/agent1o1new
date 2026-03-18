<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
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

it('includes rate limit headers on workspace API responses', function () {
    $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows");

    $response->assertSuccessful()
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining');
});

it('throttles auth login after too many attempts', function () {
    for ($i = 0; $i < 11; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'doesnotexist@example.com',
            'password' => 'wrong',
        ]);
    }

    $response->assertStatus(429);
});

it('applies execution trigger rate limit', function () {
    $workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'is_active' => true,
    ]);

    $version = WorkflowVersion::factory()->published()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $this->user->id,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}/execute",
    );

    $response->assertStatus(201)
        ->assertHeader('X-RateLimit-Limit');
});

<?php

use App\Models\PollingTrigger;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->user->workspaces()->attach($this->workspace, ['role' => 'admin']);

    $this->workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    $this->actingAs($this->user, 'api');
});

it('lists polling triggers for a workspace', function () {
    $workflowIds = Workflow::factory()->count(3)->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ])->pluck('id');

    foreach ($workflowIds as $id) {
        PollingTrigger::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $id,
        ]);
    }

    $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/polling-triggers");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'workflow_id', 'endpoint_url', 'is_active', 'interval_seconds'],
            ],
        ])
        ->assertJsonCount(3, 'data');
});

it('creates a polling trigger', function () {
    $payload = [
        'endpoint_url' => 'https://api.example.com/data',
        'http_method' => 'GET',
        'dedup_key' => 'id',
        'interval_seconds' => 300,
    ];

    $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/polling-trigger", $payload);

    $response->assertCreated()
        ->assertJsonPath('data.endpoint_url', 'https://api.example.com/data')
        ->assertJsonPath('data.interval_seconds', 300);

    $this->assertDatabaseHas('polling_triggers', [
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
    ]);
});

it('shows a specific polling trigger', function () {
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
    ]);

    $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/polling-triggers/{$trigger->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $trigger->id);
});

it('updates a polling trigger', function () {
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
        'endpoint_url' => 'https://api.old.com/data',
    ]);

    $payload = [
        'endpoint_url' => 'https://api.new.com/data',
        'is_active' => false,
    ];

    $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/polling-triggers/{$trigger->id}", $payload);

    $response->assertOk()
        ->assertJsonPath('data.endpoint_url', 'https://api.new.com/data')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('polling_triggers', [
        'id' => $trigger->id,
        'endpoint_url' => 'https://api.new.com/data',
        'is_active' => false,
    ]);
});

it('deletes a polling trigger', function () {
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
    ]);

    $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/polling-triggers/{$trigger->id}");

    $response->assertOk();

    $this->assertDatabaseMissing('polling_triggers', [
        'id' => $trigger->id,
    ]);
});

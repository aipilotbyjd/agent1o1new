<?php

use App\Engine\Nodes\Core\TriggerNode;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionMode;
use App\Enums\ExecutionNodeStatus;
use App\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\PollingTrigger;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Services\ExecutionService;
use App\Services\PollingTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupTriggerWorkspace(): array
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
        'is_active' => true,
    ]);

    $version = WorkflowVersion::factory()->published()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $owner->id,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    return [$owner, $workspace, $workflow];
}

// ══════════════════════════════════════════════════════════════
// ── ExecutionService::trigger ────────────────────────────────
// ══════════════════════════════════════════════════════════════

test('ExecutionService creates execution and dispatches job', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $service = app(ExecutionService::class);
    $execution = $service->trigger($workflow, $owner, ['key' => 'value'], ExecutionMode::Manual);

    expect($execution->workflow_id)->toBe($workflow->id)
        ->and($execution->triggered_by)->toBe($owner->id)
        ->and($execution->status)->toBe(ExecutionStatus::Pending)
        ->and($execution->mode)->toBe(ExecutionMode::Manual)
        ->and($execution->trigger_data)->toBe(['key' => 'value']);

    Queue::assertPushed(ExecuteWorkflowJob::class);
});

test('ExecutionService creates replay pack on trigger', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $service = app(ExecutionService::class);
    $execution = $service->trigger($workflow, $owner);

    $this->assertDatabaseHas('execution_replay_packs', [
        'execution_id' => $execution->id,
        'workflow_id' => $workflow->id,
    ]);
});

test('ExecutionService rejects inactive workflow', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $workflow->update(['is_active' => false]);

    $service = app(ExecutionService::class);

    expect(fn () => $service->trigger($workflow->fresh(), $owner))
        ->toThrow(\App\Exceptions\ApiException::class);
});

test('ExecutionService rejects workflow without published version', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $workflow->update(['current_version_id' => null]);

    $service = app(ExecutionService::class);

    expect(fn () => $service->trigger($workflow->fresh(), $owner))
        ->toThrow(\App\Exceptions\ApiException::class);
});

test('ExecutionService triggers with webhook mode', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $service = app(ExecutionService::class);
    $execution = $service->trigger($workflow, $owner, ['webhook_uuid' => 'test-uuid'], ExecutionMode::Webhook);

    expect($execution->mode)->toBe(ExecutionMode::Webhook);
});

test('ExecutionService triggers with scheduled mode', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $service = app(ExecutionService::class);
    $execution = $service->trigger(
        $workflow,
        $owner,
        ['trigger' => 'cron', 'cron_expression' => '*/5 * * * *'],
        ExecutionMode::Scheduled,
    );

    expect($execution->mode)->toBe(ExecutionMode::Scheduled)
        ->and($execution->trigger_data['cron_expression'])->toBe('*/5 * * * *');
});

// ══════════════════════════════════════════════════════════════
// ── PollingTriggerService::poll ──────────────────────────────
// ══════════════════════════════════════════════════════════════

test('poll fetches data and triggers execution for new records', function () {
    Queue::fake();
    Http::fake([
        'https://api.example.com/data' => Http::response([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]),
    ]);

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(2);

    $this->assertDatabaseHas('executions', [
        'workflow_id' => $workflow->id,
        'mode' => ExecutionMode::Polling->value,
    ]);

    $trigger->refresh();
    expect($trigger->poll_count)->toBe(1)
        ->and($trigger->trigger_count)->toBe(2)
        ->and($trigger->last_seen_ids)->toBe(['1', '2'])
        ->and($trigger->last_error)->toBeNull();
});

test('poll deduplicates already-seen records', function () {
    Queue::fake();
    Http::fake([
        'https://api.example.com/data' => Http::response([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ]),
    ]);

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
        'dedup_key' => 'id',
        'is_active' => true,
        'last_seen_ids' => ['1', '2'],
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(1);

    $trigger->refresh();
    expect($trigger->last_seen_ids)->toContain('3');
});

test('poll handles wrapped response data', function () {
    Queue::fake();
    Http::fake([
        'https://api.example.com/items' => Http::response([
            'data' => [
                ['id' => 10, 'value' => 'x'],
            ],
        ]),
    ]);

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/items',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(1);
});

test('poll records error on HTTP failure', function () {
    Queue::fake();
    Http::fake([
        'https://api.example.com/fail' => Http::response('Server Error', 500),
    ]);

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/fail',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(0);

    $trigger->refresh();
    expect($trigger->last_error)->not->toBeNull()
        ->and($trigger->next_poll_at)->not->toBeNull();
});

test('poll skips inactive workflow', function () {
    Queue::fake();
    Http::fake();

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $workflow->update(['is_active' => false]);

    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(0);
    Http::assertNothingSent();
});

test('poll skips workflow without published version', function () {
    Queue::fake();
    Http::fake();

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $workflow->update(['current_version_id' => null]);

    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(0);
    Http::assertNothingSent();
});

test('poll uses POST method when configured', function () {
    Queue::fake();
    Http::fake([
        'https://api.example.com/search' => Http::response([
            ['id' => 1],
        ]),
    ]);

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    $trigger = PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/search',
        'http_method' => 'POST',
        'body' => ['filter' => 'new'],
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $service = app(PollingTriggerService::class);
    $triggered = $service->poll($trigger);

    expect($triggered)->toBe(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST');
});

// ══════════════════════════════════════════════════════════════
// ── ScheduleCronWorkflows Command ────────────────────────────
// ══════════════════════════════════════════════════════════════

test('cron command dispatches due workflows', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $workflow->update([
        'trigger_type' => 'cron',
        'cron_expression' => '*/5 * * * *',
        'next_run_at' => now()->subMinute(),
    ]);

    $this->artisan('workflows:schedule-cron')
        ->assertSuccessful();

    $this->assertDatabaseHas('executions', [
        'workflow_id' => $workflow->id,
        'mode' => ExecutionMode::Scheduled->value,
    ]);

    $workflow->refresh();
    expect($workflow->last_cron_run_at)->not->toBeNull()
        ->and($workflow->next_run_at)->not->toBeNull();
});

test('cron command skips workflows not yet due', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $workflow->update([
        'trigger_type' => 'cron',
        'cron_expression' => '*/5 * * * *',
        'next_run_at' => now()->addHour(),
    ]);

    $this->artisan('workflows:schedule-cron')
        ->assertSuccessful();

    $this->assertDatabaseMissing('executions', [
        'workflow_id' => $workflow->id,
    ]);
});

test('cron command skips inactive workflows', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $workflow->update([
        'is_active' => false,
        'trigger_type' => 'cron',
        'cron_expression' => '*/5 * * * *',
        'next_run_at' => now()->subMinute(),
    ]);

    $this->artisan('workflows:schedule-cron')
        ->assertSuccessful();

    $this->assertDatabaseMissing('executions', [
        'workflow_id' => $workflow->id,
    ]);
});

test('cron command skips workflows without published version', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupTriggerWorkspace();

    $workflow->update([
        'trigger_type' => 'cron',
        'cron_expression' => '*/5 * * * *',
        'next_run_at' => now()->subMinute(),
        'current_version_id' => null,
    ]);

    $this->artisan('workflows:schedule-cron')
        ->assertSuccessful();

    $this->assertDatabaseMissing('executions', [
        'workflow_id' => $workflow->id,
    ]);
});

// ══════════════════════════════════════════════════════════════
// ── PollTriggersCommand ──────────────────────────────────────
// ══════════════════════════════════════════════════════════════

test('poll command processes due polling triggers', function () {
    Queue::fake();
    Http::fake([
        '*' => Http::response([['id' => 1]]),
    ]);

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->subMinute(),
    ]);

    $this->artisan('workflows:poll')
        ->assertSuccessful();

    $this->assertDatabaseHas('executions', [
        'workflow_id' => $workflow->id,
        'mode' => ExecutionMode::Polling->value,
    ]);
});

test('poll command skips triggers not yet due', function () {
    Queue::fake();
    Http::fake();

    [$owner, $workspace, $workflow] = setupTriggerWorkspace();
    PollingTrigger::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'endpoint_url' => 'https://api.example.com/data',
        'dedup_key' => 'id',
        'is_active' => true,
        'next_poll_at' => now()->addHour(),
    ]);

    $this->artisan('workflows:poll')
        ->assertSuccessful();

    Http::assertNothingSent();
});

// ══════════════════════════════════════════════════════════════
// ── TriggerNode Engine Handler ───────────────────────────────
// ══════════════════════════════════════════════════════════════

test('TriggerNode outputs trigger data', function () {
    $node = new TriggerNode;
    $payload = new NodePayload(
        nodeId: 'trigger_1',
        nodeType: 'trigger',
        nodeName: 'Start',
        config: ['trigger_type' => 'webhook'],
        inputData: [],
        executionMeta: [
            'trigger_data' => ['body' => ['email' => 'user@example.com']],
        ],
    );

    $result = $node->handle($payload);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['trigger_type'])->toBe('webhook')
        ->and($result->output['data']['body']['email'])->toBe('user@example.com')
        ->and($result->output['timestamp'])->not->toBeNull();
});

test('TriggerNode defaults to manual trigger type', function () {
    $node = new TriggerNode;
    $payload = new NodePayload(
        nodeId: 'trigger_1',
        nodeType: 'trigger',
        nodeName: 'Start',
        config: [],
        inputData: [],
        executionMeta: [],
    );

    $result = $node->handle($payload);

    expect($result->output['trigger_type'])->toBe('manual')
        ->and($result->output['data'])->toBe([]);
});

test('TriggerNode passes through polling trigger data', function () {
    $node = new TriggerNode;
    $payload = new NodePayload(
        nodeId: 'trigger_1',
        nodeType: 'trigger',
        nodeName: 'Start',
        config: ['trigger_type' => 'polling'],
        inputData: [],
        executionMeta: [
            'trigger_data' => [
                'trigger' => 'polling',
                'polling_trigger_id' => 42,
                'record' => ['id' => 1, 'name' => 'New Order'],
            ],
        ],
    );

    $result = $node->handle($payload);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->output['data']['record']['name'])->toBe('New Order');
});

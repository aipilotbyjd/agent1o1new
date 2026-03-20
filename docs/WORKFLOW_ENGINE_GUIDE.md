# Workflow Engine Guide

This document explains how the workflow engine currently works in this codebase, using the real implementation instead of the older migration plan.

The short version is:

1. A trigger creates an [`Execution`](../app/Models/Execution.php).
2. [`ExecutionService::trigger()`](../app/Services/ExecutionService.php) dispatches [`ExecuteWorkflowJob`](../app/Jobs/ExecuteWorkflowJob.php).
3. [`ExecuteWorkflowJob`](../app/Jobs/ExecuteWorkflowJob.php) calls [`WorkflowEngine::run()`](../app/Engine/WorkflowEngine.php).
4. [`WorkflowEngine`](../app/Engine/WorkflowEngine.php) compiles the workflow graph, schedules nodes, persists node results in batches, and finishes or suspends the execution.
5. If a node suspends the run, [`ResumeWorkflowJob`](../app/Jobs/ResumeWorkflowJob.php) later calls [`WorkflowEngine::resume()`](../app/Engine/WorkflowEngine.php).

## 1. What is the real engine path today?

The main engine path is native Laravel code under [`app/Engine/`](../app/Engine).

The execution path that actually runs inside this app is:

```text
Trigger source
-> ExecutionService::trigger()
-> Execution row + replay pack
-> ExecuteWorkflowJob
-> WorkflowEngine::run()
-> GraphCompiler + RunContext + runners
-> BatchWriter / CheckpointStore
-> Execution completed, failed, cancelled, or waiting
```

The external-engine compatibility layer has been removed.

## 2. Every place that can start a workflow

### Manual API execution

Route:

- [`routes/api.php`](../routes/api.php) -> `POST /api/v1/workspaces/{workspace}/workflows/{workflow}/execute`

Call chain:

```text
ExecutionController::store()
-> ExecutionService::trigger(..., ExecutionMode::Manual)
-> ExecuteWorkflowJob::dispatch()
-> WorkflowEngine::run()
```

Files:

- [`ExecutionController`](../app/Http/Controllers/Api/V1/ExecutionController.php)
- [`ExecutionService`](../app/Services/ExecutionService.php)
- [`ExecuteWorkflowJob`](../app/Jobs/ExecuteWorkflowJob.php)
- [`WorkflowEngine`](../app/Engine/WorkflowEngine.php)

### Public webhook execution

Route:

- [`routes/api.php`](../routes/api.php) -> `Route::match([...], 'webhook/{uuid}', ...)`

Call chain:

```text
WebhookReceiverController::handle()
-> WebhookService::handleIncoming()
-> ExecutionService::trigger(..., ExecutionMode::Webhook)
-> ExecuteWorkflowJob::dispatch()
-> WorkflowEngine::run()
```

Files:

- [`WebhookReceiverController`](../app/Http/Controllers/Api/V1/WebhookReceiverController.php)
- [`WebhookService`](../app/Services/WebhookService.php)
- [`Webhook`](../app/Models/Webhook.php)

### Polling trigger execution

Scheduler:

- [`routes/console.php`](../routes/console.php) -> `Schedule::command('workflows:poll')->everyMinute();`

Call chain:

```text
PollTriggersCommand::handle()
-> PollingTriggerService::poll()
-> ExecutionService::trigger(..., ExecutionMode::Polling)
-> ExecuteWorkflowJob::dispatch()
-> WorkflowEngine::run()
```

Files:

- [`PollTriggersCommand`](../app/Console/Commands/PollTriggersCommand.php)
- [`PollingTriggerService`](../app/Services/PollingTriggerService.php)
- [`PollingTriggerController`](../app/Http/Controllers/Api/V1/PollingTriggerController.php)

### Cron/scheduled execution

Scheduler:

- [`routes/console.php`](../routes/console.php) -> `Schedule::command('workflows:schedule-cron')->everyMinute();`

Call chain:

```text
ScheduleCronWorkflows::handle()
-> ExecutionService::trigger(..., ExecutionMode::Scheduled)
-> ExecuteWorkflowJob::dispatch()
-> WorkflowEngine::run()
```

Files:

- [`ScheduleCronWorkflows`](../app/Console/Commands/ScheduleCronWorkflows.php)
- [`Workflow`](../app/Models/Workflow.php)

### Sub-workflow execution

Call chain:

```text
WorkflowEngine::executeLoop()
-> SyncRunner::run()
-> SubWorkflowNode::handle()
-> ExecutionService::trigger(..., ExecutionMode::SubWorkflow)
-> ExecuteWorkflowJob::dispatch()
```

Files:

- [`SubWorkflowNode`](../app/Engine/Nodes/Core/SubWorkflowNode.php)
- [`ExecutionService`](../app/Services/ExecutionService.php)

### Retry and replay

Routes:

- [`routes/api.php`](../routes/api.php) -> `POST /api/v1/workspaces/{workspace}/executions/{execution}/retry`
- [`routes/api.php`](../routes/api.php) -> `POST /api/v1/workspaces/{workspace}/executions/{execution}/replay`

Call chain:

```text
ExecutionController::retry() / replay()
-> ExecutionService::retry() / replay()
-> ExecuteWorkflowJob::dispatch()
-> WorkflowEngine::run()
```

## 3. What `ExecutionService` is responsible for

[`ExecutionService`](../app/Services/ExecutionService.php) is the gateway into execution creation.

It does four important things:

1. Validates that the workflow is active and has a published version.
2. Creates the [`Execution`](../app/Models/Execution.php) row with mode, trigger data, attempt counters, and request metadata.
3. Captures an [`ExecutionReplayPack`](../app/Models/ExecutionReplayPack.php) snapshot.
4. Dispatches [`ExecuteWorkflowJob`](../app/Jobs/ExecuteWorkflowJob.php) onto `workflows-default`.

The core method is effectively:

```php
$execution = Execution::create([
    'workflow_id' => $workflow->id,
    'workspace_id' => $workflow->workspace_id,
    'status' => ExecutionStatus::Pending,
    'mode' => $mode,
    'triggered_by' => $user->id,
    'trigger_data' => $triggerData,
]);

$this->captureReplayPack($execution, $workflow);

ExecuteWorkflowJob::dispatch($execution)
    ->onQueue('workflows-default');
```

That method is the single most important entry gate in the whole workflow system.

## 4. What `ExecuteWorkflowJob` and `ResumeWorkflowJob` do

[`ExecuteWorkflowJob`](../app/Jobs/ExecuteWorkflowJob.php) is intentionally small:

```php
public function handle(WorkflowEngine $engine): void
{
    $engine->run($this->execution);
}
```

[`ResumeWorkflowJob`](../app/Jobs/ResumeWorkflowJob.php) is the same idea for waiting executions:

```php
public function handle(WorkflowEngine $engine): void
{
    $this->execution->refresh();

    if ($this->execution->status !== ExecutionStatus::Waiting) {
        return;
    }

    $engine->resume($this->execution);
}
```

So jobs are just queue wrappers. The real orchestration lives in [`WorkflowEngine`](../app/Engine/WorkflowEngine.php).

## 5. How `WorkflowEngine::run()` works

### High-level flow

[`WorkflowEngine::run()`](../app/Engine/WorkflowEngine.php):

1. Loads the workflow and current published version.
2. Compiles the version into a [`WorkflowGraph`](../app/Engine/WorkflowGraph.php) or reuses the cached one.
3. Loads workspace variables and workflow-linked credentials.
4. Creates an [`OutputBuffer`](../app/Engine/Data/OutputBuffer.php).
5. Creates a [`RunContext`](../app/Engine/RunContext.php).
6. Marks the execution as running.
7. Publishes an SSE event.
8. Enters the frontier scheduler loop in `executeLoop()`.

The setup is roughly:

```php
$graph = $this->compileGraph($version);
$variables = $this->loadVariables($execution);
$credentials = $this->loadCredentials($execution);
$outputBuffer = new OutputBuffer(...);

$context = new RunContext(
    graph: $graph,
    outputs: $outputBuffer,
    executionId: $execution->id,
    variables: $variables,
    credentials: $credentials,
);

$execution->start();
$this->executeLoop($execution, $graph, $context);
```

### The scheduler loop

The real engine behavior is in [`WorkflowEngine::executeLoop()`](../app/Engine/WorkflowEngine.php).

For every round:

1. Ask [`RunContext`](../app/Engine/RunContext.php) for ready nodes.
2. Partition them into:
   - sync nodes
   - async nodes
   - blocking/suspendable nodes
3. Execute sync nodes with [`SyncRunner`](../app/Engine/Runners/SyncRunner.php).
4. Execute async nodes with [`AsyncRunner`](../app/Engine/Runners/AsyncRunner.php).
5. If a blocking node is found, checkpoint and requeue with [`ResumeWorkflowJob`](../app/Jobs/ResumeWorkflowJob.php).
6. Flush node results in batches with [`BatchWriter`](../app/Engine/Persistence/BatchWriter.php).
7. Check for cancellation.
8. When no ready nodes remain, either complete or fail the execution.

This is the runtime heart of the engine.

## 6. How the workflow graph is compiled

[`GraphCompiler`](../app/Engine/GraphCompiler.php) converts raw `nodes` + `edges` from [`WorkflowVersion`](../app/Models/WorkflowVersion.php) into an immutable [`WorkflowGraph`](../app/Engine/WorkflowGraph.php).

It does all of this once per version:

1. Builds `nodeMap` for O(1) node lookup.
2. Builds successor and predecessor adjacency lists.
3. Computes in-degree for each node.
4. Detects cycles using Kahn's algorithm.
5. Finds start nodes.
6. Pre-compiles expressions in node config.
7. Builds downstream-consumer maps for output eviction.

That compiled graph is cached in `WorkflowEngine::compileGraph()` under:

```text
engine:graph:{workflow_version_id}
```

So the engine does not re-parse the workflow JSON every time.

## 7. How node handlers are resolved and called

### Core idea

Every node eventually becomes a class that implements [`NodeHandler`](../app/Engine/Contracts/NodeHandler.php):

```php
interface NodeHandler
{
    public function handle(NodePayload $payload): NodeResult;
}
```

### Resolution

[`NodeRegistry`](../app/Engine/NodeRegistry.php) maps a node type string to a handler class.

It resolves nodes in two layers:

1. Core/flow nodes through [`NodeType`](../app/Engine/Enums/NodeType.php).
2. App nodes through naming convention.

Examples:

- `trigger` -> [`TriggerNode`](../app/Engine/Nodes/Core/TriggerNode.php)
- `delay` -> [`DelayNode`](../app/Engine/Nodes/Flow/DelayNode.php)
- `http_request` -> [`HttpRequestNode`](../app/Engine/Nodes/Core/HttpRequestNode.php)
- `slack.send_message` -> [`SlackNode`](../app/Engine/Nodes/Apps/Slack/SlackNode.php)
- `google_sheets.append_row` -> [`GoogleSheetsNode`](../app/Engine/Nodes/Apps/Google/GoogleSheetsNode.php)

### Payload building

Before the handler runs, [`NodePayloadFactory`](../app/Engine/Runners/NodePayloadFactory.php) builds a [`NodePayload`](../app/Engine/Runners/NodePayload.php) with:

- resolved config
- gathered upstream input data
- credentials for the current node
- variables
- execution metadata

That means handlers do not talk to the graph or execution model directly. They receive a prepared input object.

## 8. Sync nodes vs async nodes vs suspendable nodes

[`WorkflowEngine::partitionNodes()`](../app/Engine/WorkflowEngine.php) decides how ready nodes run.

### Sync nodes

Run inline through [`SyncRunner`](../app/Engine/Runners/SyncRunner.php).

Typical examples:

- [`TriggerNode`](../app/Engine/Nodes/Core/TriggerNode.php)
- [`ConditionNode`](../app/Engine/Nodes/Flow/ConditionNode.php)
- [`TransformNode`](../app/Engine/Nodes/Core/TransformNode.php)
- [`SetVariableNode`](../app/Engine/Nodes/Core/SetVariableNode.php)

### Async nodes

Run through [`AsyncRunner`](../app/Engine/Runners/AsyncRunner.php).

Important details:

- small batches run inline
- larger batches are chunked
- chunks execute through `Concurrency::driver('process')->run(...)`
- results are re-hydrated back into [`NodeResult`](../app/Engine/NodeResult.php)

This is how I/O-heavy nodes can run concurrently inside the Laravel worker.

### Suspendable nodes

Suspendable nodes implement [`SuspendsExecution`](../app/Engine/Contracts/SuspendsExecution.php).

Current example:

- [`DelayNode`](../app/Engine/Nodes/Flow/DelayNode.php)

When one of these runs, the engine does not block the worker with `sleep()`. It checkpoints state and exits the job cleanly.

## 9. How data moves through the engine

### Trigger data

`Execution.trigger_data` is copied into the runtime variable bag as `__trigger_data`.

[`TriggerNode`](../app/Engine/Nodes/Core/TriggerNode.php) exposes that to downstream nodes as output:

```php
[
    'trigger_type' => ...,
    'data' => $triggerData,
    'timestamp' => now()->toIso8601String(),
]
```

### Variables

[`WorkflowEngine::loadVariables()`](../app/Engine/WorkflowEngine.php) loads workspace variables and decrypts secrets before execution starts.

[`RunContext`](../app/Engine/RunContext.php) keeps those variables for the life of the execution.

### Credentials

[`WorkflowEngine::loadCredentials()`](../app/Engine/WorkflowEngine.php) loads workflow-linked credentials by pivot `node_id`.

[`RunContext::getCredential()`](../app/Engine/RunContext.php) also refreshes expiring OAuth credentials through [`OAuthCredentialFlowService`](../app/Services/OAuthCredentialFlowService.php) when needed.

### Upstream outputs

[`RunContext::gatherInputData()`](../app/Engine/RunContext.php) collects outputs from predecessor nodes.

If a node has only one predecessor, the engine flattens that predecessor's output into the top level for convenience, while still keeping the namespaced predecessor key.

## 10. How expressions work

[`ExpressionParser`](../app/Engine/Data/ExpressionParser.php) handles templates like:

```text
{{ $nodes.http_1.output.body.id }}
{{ $trigger.body.email }}
{{ $vars.api_key }}
{{ $execution.id }}
{{ $loop.item.name }}
```

The engine uses it in two phases:

1. Compile phase:
   - [`GraphCompiler`](../app/Engine/GraphCompiler.php) pre-compiles expressions into token trees.
2. Runtime phase:
   - [`NodePayloadFactory`](../app/Engine/Runners/NodePayloadFactory.php) resolves those tokens against the current execution context.

The expression context comes from [`RunContext::buildExpressionContext()`](../app/Engine/RunContext.php) and includes:

- `nodes`
- `trigger`
- `vars`
- `env`
- `execution`
- `loop`

## 11. How the frontier advances

[`RunContext`](../app/Engine/RunContext.php) is the mutable state machine for a single execution.

It keeps:

- remaining in-degree per node
- ready queue
- completed node results
- variables
- frame stack
- output buffer
- flush counters
- sequence numbers

When a node completes:

1. the node leaves the ready queue
2. its result is stored
3. successors are chosen
4. successor in-degrees are decremented
5. newly unblocked successors move into the ready queue

Branching is handled through `activeBranches`, so condition-like nodes only advance edges that match the chosen source handles.

## 12. How persistence works

### Execution rows

[`Execution`](../app/Models/Execution.php) stores high-level lifecycle data:

- pending
- running
- waiting
- completed
- failed
- cancelled

Key state methods:

- `start()`
- `complete()`
- `fail()`
- `cancel()`
- `markWaiting()`
- `resume()`

### Node rows

[`BatchWriter`](../app/Engine/Persistence/BatchWriter.php) accumulates `execution_nodes` rows in memory and flushes them with one `upsert()` call.

That avoids writing one database row per node step immediately.

### Checkpoints

[`CheckpointStore`](../app/Engine/Persistence/CheckpointStore.php) writes:

- frontier state
- output snapshots
- frame stack
- next sequence number
- suspend metadata

into [`ExecutionCheckpoint`](../app/Models/ExecutionCheckpoint.php).

### Replay packs

[`ExecutionService::captureReplayPack()`](../app/Services/ExecutionService.php) stores workflow snapshot + trigger snapshot inside [`ExecutionReplayPack`](../app/Models/ExecutionReplayPack.php).

That is used by replay mode.

## 13. How waiting/resume works

The suspend path is:

```text
WorkflowEngine::handleSuspension()
-> NodeHandler implements SuspendsExecution
-> create NodeResult for the suspending node
-> flush BatchWriter
-> CheckpointStore::save()
-> Execution::markWaiting()
-> dispatch ResumeWorkflowJob with delay
```

The resume path is:

```text
ResumeWorkflowJob::handle()
-> WorkflowEngine::resume()
-> CheckpointStore::load()
-> RunContext::fromCheckpoint()
-> Execution::resume()
-> CheckpointStore::delete()
-> executeLoop() continues from saved frontier state
```

The reference implementation for suspension is [`DelayNode`](../app/Engine/Nodes/Flow/DelayNode.php).

## 14. How memory usage is controlled

[`OutputBuffer`](../app/Engine/Data/OutputBuffer.php) exists to stop executions from keeping every output in memory forever.

It does two important things:

1. Reference counting
   - outputs are evicted once downstream consumers have finished with them
2. Disk spilling
   - large outputs are written to `storage/app/engine-outputs/{executionId}`

That is why [`GraphCompiler`](../app/Engine/GraphCompiler.php) builds `downstreamConsumers` and why [`RunContext`](../app/Engine/RunContext.php) calls `outputs->release(...)`.

## 15. How real-time updates work

[`WorkflowEngine::publishSseEvent()`](../app/Engine/WorkflowEngine.php) publishes execution events to Redis in two forms:

1. a stream entry for catch-up/reliability
2. a pub/sub message for live subscribers

Keys/channels:

- stream: `execution:{executionId}:events`
- channel: `linkflow:execution:{executionId}:live`

The engine publishes events like:

- `execution.started`
- `execution.node_started`
- `execution.node_completed`
- `execution.suspended`
- `execution.resumed`
- `execution.completed`
- `execution.failed`
- `execution.cancelled`

This Redis stream + pub/sub pattern is now produced directly by the native engine only.

## 16. Activation side effects: external webhook auto-registration

[`Workflow::activate()`](../app/Models/Workflow.php) does more than flip `is_active`.

It also calls [`WebhookAutoRegistrationService`](../app/Services/WebhookAutoRegistrationService.php), which:

1. inspects the current workflow version
2. finds trigger nodes with provider-backed webhook support
3. resolves credentials for the trigger node
4. registers the webhook with the external provider
5. stores the local [`Webhook`](../app/Models/Webhook.php) record

Provider registrars are resolved through [`WebhookRegistrarRegistry`](../app/Engine/WebhookRegistrars/WebhookRegistrarRegistry.php).

Today that registry supports:

- `github`
- `stripe`

## 17. Best files to read if you want to understand the engine quickly

Read these in order:

1. [`app/Services/ExecutionService.php`](../app/Services/ExecutionService.php)
2. [`app/Jobs/ExecuteWorkflowJob.php`](../app/Jobs/ExecuteWorkflowJob.php)
3. [`app/Engine/WorkflowEngine.php`](../app/Engine/WorkflowEngine.php)
4. [`app/Engine/RunContext.php`](../app/Engine/RunContext.php)
5. [`app/Engine/GraphCompiler.php`](../app/Engine/GraphCompiler.php)
6. [`app/Engine/Runners/NodePayloadFactory.php`](../app/Engine/Runners/NodePayloadFactory.php)
7. [`app/Engine/NodeRegistry.php`](../app/Engine/NodeRegistry.php)
8. [`app/Engine/Data/ExpressionParser.php`](../app/Engine/Data/ExpressionParser.php)
9. [`app/Engine/Persistence/BatchWriter.php`](../app/Engine/Persistence/BatchWriter.php)
10. [`app/Engine/Persistence/CheckpointStore.php`](../app/Engine/Persistence/CheckpointStore.php)

## 18. Best executable tests to read

These tests explain the system with runnable examples:

- [`tests/Feature/TriggerServiceTest.php`](../tests/Feature/TriggerServiceTest.php)
  - manual trigger
  - webhook mode
  - cron mode
  - polling mode
- [`tests/Feature/Engine/WorkflowEngineTest.php`](../tests/Feature/Engine/WorkflowEngineTest.php)
  - basic engine execution
  - linear graphs
  - parallel branches
  - error handling
- [`tests/Feature/Engine/SuspendResumeTest.php`](../tests/Feature/Engine/SuspendResumeTest.php)
  - delay suspension
  - checkpoint persistence
  - resume flow

## 19. One-page mental model

If you want the simplest correct mental model, use this:

```text
WorkflowVersion stores a DAG as JSON.
GraphCompiler turns that DAG into a compiled WorkflowGraph.
WorkflowEngine runs the graph with a frontier scheduler.
RunContext tracks what is ready, done, and still needed.
NodePayloadFactory prepares each node's config/input/credentials.
SyncRunner and AsyncRunner execute handlers.
BatchWriter persists node results.
CheckpointStore persists waiting state.
ResumeWorkflowJob continues later.
ExecutionService is the only normal way new executions get created.
```

That is the full workflow engine in this repository.

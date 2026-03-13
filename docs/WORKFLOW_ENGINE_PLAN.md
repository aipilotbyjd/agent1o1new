# Workflow Engine — Architecture & Implementation Plan

> **Goal**: Replace the external Go execution engine with a native Laravel workflow engine built directly into the application. Optimised for throughput, low latency, memory efficiency, and horizontal scalability.

---

## Table of Contents

1. [Current Architecture (What We're Replacing)](#1-current-architecture)
2. [New Architecture (Overview)](#2-new-architecture)
3. [Directory Structure & File Map](#3-directory-structure--file-map)
4. [Core Components (Detailed)](#4-core-components)
5. [Node Handlers](#5-node-handlers)
6. [Expression & Data Resolution](#6-expression--data-resolution)
7. [Persistence Strategy](#7-persistence-strategy)
8. [Concurrency & Async I/O](#8-concurrency--async-io)
9. [Queue & Horizon Strategy](#9-queue--horizon-strategy)
10. [Database Schema Changes](#10-database-schema-changes)
11. [Changes to Existing Code](#11-changes-to-existing-code)
12. [Code to Remove](#12-code-to-remove)
13. [Execution Flow (Walkthrough)](#13-execution-flow-walkthrough)
14. [Performance Optimisations Summary](#14-performance-optimisations-summary)
15. [New Dependencies](#15-new-dependencies)
16. [Implementation Phases](#16-implementation-phases)
17. [Risks & Guardrails](#17-risks--guardrails)

---

## 1. Current Architecture

```
User/Webhook → Laravel API → ExecuteWorkflowJob
    → Builds JSON message
    → Pushes to Redis Stream (partitioned)
    → Go engine picks up from Redis
    → Go engine walks the DAG, makes HTTP calls (goroutines)
    → Go engine POSTs results back via /api/v1/jobs/callback
    → JobCallbackController writes ExecutionNode rows
    → SSE event published
```

**Problems being solved**:

| Problem | Impact |
|---|---|
| Two services to deploy, monitor, and debug | Operational overhead |
| Redis Streams as transport between services | Latency + failure modes |
| HMAC callback authentication | Complexity for internal communication |
| Go engine fetches credentials/definitions via HTTP | Extra round-trips |
| Per-node DB writes from callback controller | N queries per execution |
| Workflow stats recomputed with `COUNT(*)` per execution | Wasteful at scale |
| No compiled/cached workflow plans | Re-parses JSON every run |

---

## 2. New Architecture

```
User/Webhook → Laravel API → ExecuteWorkflowJob
    → WorkflowEngine::run($execution)
    → Loads cached WorkflowGraph (compiled once per version)
    → Frontier scheduler: sync nodes run instantly, HTTP nodes fire concurrently via Amp
    → Results batched and flushed to DB periodically (not per-node)
    → Long delays → checkpoint + requeue
    → Done → final flush, SSE event, async credit metering
```

**Key design principles**:

1. **One queue job per execution** (not per node, not per branch)
2. **Concurrent I/O inside the worker** via Amp Fibers (replaces Go goroutines)
3. **Compile once, run many** — workflow graph is pre-compiled and cached
4. **Batch writes** — accumulate node results in memory, flush in bulk
5. **Checkpoint and resume** — for delays, waits, and long-running workflows
6. **Off-load non-critical work** — credits, stats, log enrichment run async

---

## 3. Directory Structure & File Map

```
app/Engine/
│
├── WorkflowEngine.php                 # The main orchestrator — runs the frontier loop
├── WorkflowGraph.php                  # Compiled graph: node map, adjacency, expressions
├── GraphCompiler.php                  # Compiles WorkflowVersion JSON → WorkflowGraph
├── RunContext.php                     # Runtime state: frontier queue, node outputs, counters
├── NodeResult.php                     # Value object returned by every handler
│
├── Contracts/
│   └── NodeHandler.php                # Interface: handle(NodePayload): NodeResult
│
├── Runners/
│   ├── SyncRunner.php                 # Executes instant nodes (transforms, conditions)
│   ├── AsyncRunner.php                # Executes HTTP nodes concurrently via Amp
│   └── NodePayload.php                # Per-node input bag: config, upstream data, credentials
│
├── Nodes/                             # One handler per node type
│   ├── HttpRequestNode.php            # HTTP/API calls with auth, retry, timeout
│   ├── TransformNode.php              # Data mapping and transformation
│   ├── ConditionNode.php              # If/switch — decides which branch to follow
│   ├── TriggerNode.php                # Passthrough — extracts trigger data
│   ├── DelayNode.php                  # Pauses execution (short: sleep, long: requeue)
│   ├── LoopNode.php                   # Iterates over arrays, runs sub-graph per item
│   ├── MergeNode.php                  # Waits for parallel branches, combines outputs
│   ├── SubWorkflowNode.php            # Runs another workflow inline
│   └── SetVariableNode.php            # Writes a value to the variable bag
│
├── Data/
│   ├── CredentialStore.php            # Decrypts and serves credentials for nodes
│   ├── VariableStore.php              # Workspace variables + expression interpolation
│   ├── ExpressionParser.php           # Compiles {{ $nodes.X.output.y }} into token arrays
│   └── OutputBuffer.php               # Holds node outputs in memory, spills large ones to disk
│
├── Persistence/
│   ├── BatchWriter.php                # Batched DB::upsert for ExecutionNode rows
│   └── Checkpoint.php                 # Redis hot state + DB durable save/restore
│
├── Throttle/
│   ├── ConnectionLimiter.php          # Per-host, per-provider HTTP concurrency caps
│   └── WorkerBudget.php               # Time/memory budget — triggers checkpoint + requeue
│
├── Enums/
│   └── NodeType.php                   # Maps node_type strings → handler classes
│
└── Exceptions/
    ├── NodeFailedException.php
    ├── CycleDetectedException.php
    └── ExecutionTimedOutException.php
```

### File Responsibilities

| File | One-line purpose |
|---|---|
| `WorkflowEngine.php` | Entry point. Loads graph, creates runtime context, runs the frontier loop until done or suspended. |
| `WorkflowGraph.php` | Immutable compiled representation of a workflow version. Cached by version ID. Contains node map, adjacency lists, pre-parsed expressions, output dependency map. |
| `GraphCompiler.php` | Takes `WorkflowVersion->nodes` + `edges` JSON arrays, validates the DAG, performs topological analysis, compiles expressions, and produces a `WorkflowGraph`. |
| `RunContext.php` | Mutable runtime state for a single execution. Tracks the ready-node frontier, completed nodes, in-degree counters, node output references, loop/frame stack, flush counters. |
| `NodeResult.php` | Simple value object: `status` (completed/failed/skipped), `output` (array), `error` (array\|null), `durationMs` (int). |
| `NodeHandler.php` | Contract: `handle(NodePayload $payload): NodeResult`. Every node type implements this. |
| `SyncRunner.php` | Takes a batch of sync-type nodes, executes them one-by-one instantly (no I/O). Returns results to `RunContext`. |
| `AsyncRunner.php` | Takes a batch of HTTP-type nodes, fires them all concurrently using Amp's async HTTP client. Returns results when all complete. Respects concurrency limits. |
| `NodePayload.php` | Everything a handler needs: node config, resolved input data from upstream nodes, credentials (if needed), variables, execution metadata. |
| `HttpRequestNode.php` | Makes HTTP calls. Supports GET/POST/PUT/PATCH/DELETE, auth (bearer/basic/header/OAuth), custom headers, body templates, response parsing, configurable timeout and retry. |
| `TransformNode.php` | Maps/transforms data using expression templates. Reshapes upstream outputs into new structures. |
| `ConditionNode.php` | Evaluates a condition expression. Returns which output branches to activate (supports if/else and switch/case). |
| `TriggerNode.php` | No-op handler. Passes through `trigger_data` from the execution as its output. Start node for every workflow. |
| `DelayNode.php` | Short delays (< 2s): `usleep()` in-process. Long delays: signals `RunContext` to checkpoint and requeue with `->delay()`. |
| `LoopNode.php` | Iterates over an array from upstream output. For each item, runs the loop's sub-graph. Generates unique `node_run_key` per iteration (`nodeId#iter=N`). |
| `MergeNode.php` | Collects outputs from multiple upstream branches into a single merged output. Only becomes "ready" when all its predecessors have completed. |
| `SubWorkflowNode.php` | Loads another workflow's graph and runs it inline within the same `WorkflowEngine` instance as a nested frame. Creates a child `Execution` record for audit. |
| `SetVariableNode.php` | Writes a computed value to the `RunContext` variable bag, making it available to all downstream nodes. |
| `CredentialStore.php` | Given a workflow + node ID, loads the linked credential, decrypts it, and returns the data. Replaces `InternalEngineController::credential()`. |
| `VariableStore.php` | Loads workspace variables (decrypting secrets), merges with runtime variables from `SetVariableNode`. |
| `ExpressionParser.php` | At compile time: parses `{{ $nodes.httpCall.output.body.token }}` into `[{type: 'path', node: 'httpCall', path: ['output','body','token']}]`. At runtime: resolves tokens via array access — no regex, no eval. |
| `OutputBuffer.php` | Holds completed node outputs in memory. Evicts outputs whose downstream consumers have all run (ref-counting). Spills outputs > 256 KB to `storage/app/engine-outputs/` and keeps a file reference instead. |
| `BatchWriter.php` | Accumulates `ExecutionNode` rows in memory. Flushes via `DB::table('execution_nodes')->upsert(...)` every 25 nodes or 500 ms, and always on suspend/complete/fail. |
| `Checkpoint.php` | Serialises `RunContext` to Redis (hot, fast) and to an `execution_checkpoints` DB row (durable, on suspend). On resume, restores `RunContext` from checkpoint so the engine continues exactly where it left off. |
| `ConnectionLimiter.php` | Enforces per-host (10), per-provider, and per-workspace HTTP concurrency limits. Uses Redis-backed token buckets shared across workers. Prevents overwhelming external APIs. |
| `WorkerBudget.php` | Monitors wall-clock time (max 60s per job segment) and memory usage (max 80% of PHP limit). When budget is exceeded, signals `WorkflowEngine` to checkpoint and requeue rather than crash. |
| `NodeType.php` | Backed enum mapping `'http_request'` → `HttpRequestNode::class`, `'condition'` → `ConditionNode::class`, etc. Single source of truth for handler resolution. |

---

## 4. Core Components

### 4.1 GraphCompiler

**Input**: `WorkflowVersion->nodes` (JSON array) + `WorkflowVersion->edges` (JSON array)

**Output**: `WorkflowGraph` (cached)

**What it does**:
1. Builds `nodeMap[nodeId] → nodeDefinition` for O(1) lookup
2. Builds `successors[nodeId] → [targetNodeIds]` adjacency list from edges
3. Builds `predecessors[nodeId] → [sourceNodeIds]` reverse adjacency
4. Computes `inDegree[nodeId] → int` (number of predecessors)
5. Detects cycles via topological sort (Kahn's algorithm) — throws `CycleDetectedException`
6. Identifies start nodes (inDegree === 0)
7. Pre-parses all expression templates in node configs via `ExpressionParser`
8. Builds `outputDependencyMap[nodeId] → [fields referenced by downstream nodes]` — used for memory optimisation (only keep what's needed)

**Caching**: Cached by `workflow_version_id` in:
- APCu (in-worker memory, fastest)
- Redis (cross-worker, fallback)
- Invalidated when a version is published

### 4.2 WorkflowEngine

**The main orchestrator.** Called from `ExecuteWorkflowJob::handle()`.

```php
class WorkflowEngine
{
    public function run(Execution $execution): void;
    public function resume(Execution $execution, Checkpoint $checkpoint): void;
}
```

**`run()` algorithm**:

```
1. Load or compile WorkflowGraph for this workflow version
2. Initialise RunContext: frontier = start nodes, empty output buffer
3. Mark execution as Running

4. LOOP while RunContext has ready nodes:
    a. Pull next batch of ready nodes from frontier
    b. Partition into: sync[], async[], blocking[]

    c. Execute sync nodes instantly via SyncRunner
       → For each completed node: update RunContext frontier (advance successors)

    d. Execute async nodes concurrently via AsyncRunner (Amp)
       → All HTTP calls fire simultaneously
       → For each completed node: update RunContext frontier

    e. If blocking nodes exist (delay, wait):
       → Checkpoint RunContext
       → Dispatch ResumeWorkflowJob with delay
       → RETURN (worker is freed)

    f. If BatchWriter flush threshold reached:
       → Flush accumulated ExecutionNode rows to DB (single upsert)
       → Publish SSE progress events

    g. If WorkerBudget exceeded (time or memory):
       → Checkpoint RunContext
       → Dispatch ResumeWorkflowJob (immediate, no delay)
       → RETURN

5. Final flush: write remaining ExecutionNode rows
6. Mark execution as Completed (or Failed if any node failed)
7. Publish SSE completion event
8. Dispatch CalculateCreditsJob to engine-post queue
9. Increment workflow execution_count atomically
```

### 4.3 RunContext

**Mutable runtime state** for a single execution.

```php
class RunContext
{
    // Graph state
    public array $remainingInDegree;    // nodeId → int (decremented as predecessors complete)
    public array $completedNodes;       // nodeId → NodeResult
    public array $readyQueue;           // nodes whose inDegree reached 0

    // Output management
    public OutputBuffer $outputs;       // node outputs with ref-counting + spill

    // Variables
    public array $variables;            // workspace + runtime variables

    // Loop/frame tracking
    public array $frameStack;           // for nested loops and sub-workflows

    // Flush tracking
    public int $completedSinceFlush;
    public float $lastFlushAt;

    // Sequence counter
    public int $nextSequence;

    public function complete(string $nodeId, NodeResult $result): void;
    public function getReadyNodes(): array;
    public function shouldFlush(): bool;
    public function shouldSuspend(): bool;
}
```

When a node completes:
1. Store its result in `completedNodes` and `OutputBuffer`
2. For each successor: decrement `remainingInDegree`
3. If a successor's `remainingInDegree` reaches 0 → add to `readyQueue`
4. Check if the completed node's output can be evicted (all downstream consumers done)

### 4.4 NodePayload

**Immutable input bag** passed to every handler.

```php
class NodePayload
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $nodeType,
        public readonly string $nodeName,
        public readonly array $config,          // node-specific configuration
        public readonly array $inputData,       // resolved upstream data (expressions already evaluated)
        public readonly ?array $credentials,    // decrypted credential data (if node needs one)
        public readonly array $variables,       // workspace + runtime variables
        public readonly array $executionMeta,   // execution_id, workspace_id, etc.
        public readonly ?string $nodeRunKey,     // unique key for loop iterations
    ) {}
}
```

### 4.5 NodeResult

```php
class NodeResult
{
    public function __construct(
        public readonly ExecutionNodeStatus $status,
        public readonly ?array $output = null,
        public readonly ?array $error = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $activeBranch = null,  // for conditional nodes
        public readonly ?array $loopItems = null,       // for loop nodes
    ) {}
}
```

---

## 5. Node Handlers

Every handler implements `NodeHandler`:

```php
interface NodeHandler
{
    public function handle(NodePayload $payload): NodeResult;
}
```

### Handler Map (NodeType Enum)

| `node_type` string | Handler Class | Execution Mode |
|---|---|---|
| `trigger` | `TriggerNode` | Sync |
| `http_request` | `HttpRequestNode` | Async |
| `transform` / `code` | `TransformNode` | Sync |
| `condition` / `if` / `switch` | `ConditionNode` | Sync |
| `set_variable` | `SetVariableNode` | Sync |
| `merge` | `MergeNode` | Sync |
| `loop` | `LoopNode` | Sync (orchestrates) |
| `delay` / `wait` | `DelayNode` | Blocking |
| `sub_workflow` | `SubWorkflowNode` | Sync (inline) |

### Handler Details

#### HttpRequestNode

The most important handler. Responsible for external API calls.

**Config fields**: `method`, `url`, `headers`, `body`, `auth`, `timeout`, `retryOnFail`, `maxRetries`, `retryDelay`

**Behaviour**:
- URL and body templates are pre-resolved by `ExpressionParser` before reaching the handler
- Uses Amp's async HTTP client (connection-pooled, concurrent)
- Respects `ConnectionLimiter` per-host caps
- Retry with exponential backoff (handled inside the handler, not via queue retry)
- Timeout per node (from config, default 30s)
- Returns response body, status code, and headers as output

#### ConditionNode

**Config fields**: `conditions` (array of `{expression, outputBranch}`), `fallbackBranch`

**Behaviour**:
- Evaluates each condition expression against upstream data
- Returns `NodeResult->activeBranch` indicating which edge(s) to follow
- `WorkflowEngine` uses `activeBranch` to selectively advance only the chosen successors

#### LoopNode

**Config fields**: `itemsExpression` (path to array in upstream output), `maxIterations`, `batchSize`

**Behaviour**:
- Resolves `itemsExpression` to get the array to iterate
- For each item (up to `maxIterations`):
  - Creates a loop frame on `RunContext->frameStack`
  - Runs the loop's sub-graph with the current item as input
  - Generates unique `nodeRunKey` per iteration: `nodeId#iter=0`, `nodeId#iter=1`, etc.
- Collects all iteration outputs into an array as the loop node's final output

#### DelayNode

**Config fields**: `delaySeconds`, `delayUntil` (expression)

**Behaviour**:
- If delay ≤ 2 seconds: `usleep()` in-process (avoid queue overhead)
- If delay > 2 seconds:
  - Signal `RunContext->shouldSuspend()` to return true
  - `WorkflowEngine` checkpoints and dispatches `ResumeWorkflowJob::dispatch($executionId)->delay($delaySeconds)`
  - Worker is freed for other work

#### MergeNode

**No special handler logic.** The merge behaviour is handled by the frontier scheduler:
- A merge node has multiple predecessors (inDegree > 1)
- It only enters the ready queue when ALL predecessors have completed
- Its input data is the combined outputs of all predecessor nodes

---

## 6. Expression & Data Resolution

### The Problem

Nodes reference each other's outputs: `{{ $nodes.httpCall.output.body.users[0].name }}`

This must be **fast** — it runs for every field of every node.

### The Solution: Compile Once, Resolve via Array Access

#### At Compile Time (GraphCompiler)

`ExpressionParser` converts template strings into token arrays:

```
"Bearer {{ $nodes.auth.output.token }}" →
[
    { type: "literal", value: "Bearer " },
    { type: "path",    node: "auth", path: ["output", "token"] }
]
```

These compiled tokens are stored in `WorkflowGraph`.

#### At Runtime (WorkflowEngine)

Resolution is pure array access — no regex, no string parsing, no eval:

```php
foreach ($tokens as $token) {
    if ($token['type'] === 'literal') {
        $result .= $token['value'];
    } else {
        $result .= data_get($outputs[$token['node']], implode('.', $token['path']));
    }
}
```

#### What Expressions Can Reference

| Prefix | Resolves to |
|---|---|
| `$nodes.{nodeId}.output` | Completed node's output data |
| `$trigger` | Execution's trigger_data |
| `$vars.{key}` | Workspace or runtime variable |
| `$env.{key}` | Config values (non-secret) |
| `$execution.id` | Current execution metadata |
| `$loop.index` | Current loop iteration index |
| `$loop.item` | Current loop item |

---

## 7. Persistence Strategy

### 7.1 BatchWriter (Hot Path)

**Instead of**: `ExecutionNode::updateOrCreate()` per node (N DB calls)

**We do**: Accumulate rows in memory, flush with one `DB::upsert()`:

```php
// Flush triggers:
// - Every 25 completed nodes
// - Every 500ms since last flush
// - On suspend, failure, or completion (always)

DB::table('execution_nodes')->upsert(
    $accumulatedRows,
    ['execution_id', 'node_run_key'],          // unique key
    ['status', 'finished_at', 'duration_ms', 'output_data', 'error', 'sequence']
);
```

**Result**: A 50-node workflow does 2-3 DB calls instead of 50.

### 7.2 Checkpoint (Suspend/Resume)

When the engine needs to pause (delay, time budget, memory budget):

1. **Hot checkpoint** → Serialise `RunContext` to Redis key `engine:checkpoint:{executionId}` with TTL
2. **Durable checkpoint** → Write to `execution_checkpoints` table (on explicit suspend only)
3. Dispatch `ResumeWorkflowJob` with appropriate delay

On resume:
1. Load checkpoint → reconstruct `RunContext`
2. Call `WorkflowEngine::resume($execution, $checkpoint)`
3. Frontier loop continues exactly where it left off

### 7.3 OutputBuffer (Memory Management)

| Strategy | When | How |
|---|---|---|
| **Keep only referenced fields** | Always | `WorkflowGraph->outputDependencyMap` tells us which fields downstream nodes need. Discard everything else. |
| **Ref-count eviction** | Always | Each output tracks how many downstream nodes still need it. When count hits 0, evict from memory. |
| **Spill to disk** | Output > 256 KB | Write to `storage/app/engine-outputs/{executionId}/{nodeRunKey}.json`. Keep a `{$ref: "file://..."}` pointer in memory. Load on demand when a downstream node needs it. |

---

## 8. Concurrency & Async I/O

### Why Amp (Not ReactPHP, Not Raw Fibers)

| | Amp v3 | ReactPHP | Raw Fibers |
|---|---|---|---|
| API style | Fiber-based (looks synchronous) | Callback/Promise-based | Manual scheduling |
| HTTP client | `amphp/http-client` — excellent | `react/http` — good | None built-in |
| Connection pooling | Built-in | Manual | None |
| Fits in Laravel queue workers | Yes (drop-in) | Needs event loop management | Yes but no I/O |
| Learning curve | Low | Medium | High |

### AsyncRunner Architecture

```php
class AsyncRunner
{
    private HttpClient $httpClient;       // Amp pooled client — lives for worker lifetime
    private ConnectionLimiter $limiter;

    public function runBatch(array $nodes, RunContext $context): array
    {
        $futures = [];
        foreach ($nodes as $node) {
            $futures[$node->nodeId] = async(function () use ($node, $context) {
                $this->limiter->acquire($node->targetHost);
                try {
                    $handler = $this->resolveHandler($node->nodeType);
                    return $handler->handle($node->payload);
                } finally {
                    $this->limiter->release($node->targetHost);
                }
            });
        }

        return Future\await($futures);
        // All HTTP calls run concurrently — like goroutines
    }
}
```

### Concurrency Limits

| Level | Default | Purpose |
|---|---|---|
| Per host | 10 concurrent | Don't overwhelm a single external API |
| Per provider (e.g. OpenAI) | 20 concurrent | Respect provider rate limits |
| Per workspace | 50 concurrent | Fair scheduling between workspaces |
| Per worker process | 100 concurrent | Prevent worker exhaustion |

Implemented via Redis-backed semaphores (`ConnectionLimiter`), shared across all queue workers.

---

## 9. Queue & Horizon Strategy

### Queue Topology

| Queue Name | Purpose | Horizon Workers |
|---|---|---|
| `engine-high` | Webhook-triggered executions, wait-mode webhooks | Auto-scale 2–8 |
| `engine-default` | Manual triggers, scheduled runs | Auto-scale 2–10 |
| `engine-resume` | Delayed resumes, checkpoint resumes, retries | Auto-scale 1–4 |
| `engine-post` | Credit metering, workflow stats, log enrichment | Fixed 2 |

### Job Types

| Job | Queue | Purpose |
|---|---|---|
| `ExecuteWorkflowJob` | `engine-high` or `engine-default` | Starts a new execution |
| `ResumeWorkflowJob` (new) | `engine-resume` | Resumes from a checkpoint after delay/budget |
| `CalculateCreditsJob` (new) | `engine-post` | Meters credits after execution completes |
| `UpdateWorkflowStatsJob` (new) | `engine-post` | Updates `execution_count`, `success_rate` atomically |

### Job Settings

```php
// ExecuteWorkflowJob
public int $tries = 2;         // 1 attempt + 1 retry
public int $timeout = 120;     // worker-level timeout (engine has its own 60s budget)
public int $maxExceptions = 1;

// ResumeWorkflowJob
public int $tries = 3;
public int $timeout = 120;
```

### Horizon Config

```php
'environments' => [
    'production' => [
        'engine-high' => [
            'connection' => 'redis',
            'queue' => ['engine-high'],
            'minProcesses' => 2,
            'maxProcesses' => 8,
            'balanceMaxShift' => 2,
            'balanceCooldown' => 3,
            'memory' => 256,
            'maxTime' => 3600,
            'maxJobs' => 500,    // restart worker after 500 jobs (prevent memory leaks)
        ],
        // ... similar for other queues
    ],
],
```

---

## 10. Database Schema Changes

### 10.1 Modify `execution_nodes` Table

```php
// Migration: add columns for loop support and batch upsert key
Schema::table('execution_nodes', function (Blueprint $table) {
    $table->string('node_run_key')->after('node_id');
    $table->unsignedInteger('loop_index')->nullable()->after('sequence');
    $table->string('parent_frame', 100)->nullable()->after('loop_index');

    // Replace old unique key with new one that supports loops
    $table->unique(['execution_id', 'node_run_key'], 'exec_node_run_unique');
});
```

**`node_run_key` examples**:
- Simple node: `"send_email"`
- Loop iteration: `"send_email#iter=3"`
- Nested loop: `"send_email#iter=3/inner=7"`
- Sub-workflow: `"sub:wf42:send_email"`

### 10.2 New `execution_checkpoints` Table

```php
Schema::create('execution_checkpoints', function (Blueprint $table) {
    $table->id();
    $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
    $table->json('frontier_state');        // ready queue + remaining in-degree map
    $table->json('output_refs');           // node output data or spill file references
    $table->json('frame_stack')->nullable(); // loop/sub-workflow frame state
    $table->unsignedInteger('next_sequence')->default(0);
    $table->string('suspend_reason', 50)->nullable(); // 'delay', 'budget', 'waiting'
    $table->timestamp('resume_at')->nullable();
    $table->unsignedInteger('checkpoint_version')->default(1);
    $table->timestamps();
});
```

### 10.3 New `compiled_plans` Table (Optional — Can Use Redis/APCu Only)

```php
Schema::create('compiled_plans', function (Blueprint $table) {
    $table->foreignId('workflow_version_id')->primary()->constrained()->cascadeOnDelete();
    $table->binary('compiled_data');       // serialized WorkflowGraph
    $table->string('plan_hash', 64);
    $table->timestamp('compiled_at');
});
```

### 10.4 Modify `executions` Table

```php
// Add atomic counters (avoid COUNT(*) recalculation)
Schema::table('executions', function (Blueprint $table) {
    $table->unsignedInteger('node_count')->default(0)->after('credits_consumed');
    $table->unsignedInteger('completed_node_count')->default(0)->after('node_count');
});
```

---

## 11. Changes to Existing Code

| File | What Changes | Why |
|---|---|---|
| `ExecuteWorkflowJob` | Remove Redis Stream logic. Call `WorkflowEngine::run($execution)` instead. | Engine runs in-process now. |
| `ExecutionService::trigger()` | Remove inline `captureReplayPack()`. Queue to `engine-high` or `engine-default` based on mode. | Replay capture moves into `WorkflowEngine`. |
| `ExecutionService::cancel()` | Replace Redis cancel signal with: set a Redis flag `engine:cancel:{executionId}` + update checkpoint. | Engine checks this flag each iteration. |
| `Execution` model | Add `node_count`, `completed_node_count` fields. | Atomic counters replace `COUNT(*)`. |
| `ExecutionNode` model | Add `node_run_key`, `loop_index`, `parent_frame` to `$fillable`. | Loop/sub-workflow support. |
| `WorkflowVersion` model | Add `compiledGraph()` method that loads/compiles via `GraphCompiler`. | Convenient access to cached graph. |
| `Workflow` model | Use `$this->increment('execution_count')` instead of `COUNT(*)` recalculation. | Performance. |
| `WebhookService` | No changes — it already calls `ExecutionService::trigger()`. | Works as-is. |
| `SseController` | No changes — still reads from Redis PubSub/Streams. | Works as-is. |
| `CreditMeterService` | Called from `CalculateCreditsJob` instead of inline in callback. | Off the hot path. |

---

## 12. Code to Remove

Once the engine is fully operational, remove these Go-engine artifacts:

| File/Concept | Reason for Removal |
|---|---|
| `JobCallbackController` | Go engine no longer calls back. Results are written directly. |
| `InternalEngineController` | Go engine no longer fetches credentials/definitions via HTTP. |
| `EngineDashboardController` | Go engine health/DLQ/cache endpoints no longer apply. Replace with Horizon dashboard. |
| `EngineHealthService` | HTTP calls to Go engine health endpoints. |
| `engine.signature` middleware | HMAC verification for Go engine requests. |
| `JobStatus` model + migration | Job tracking was for Redis Stream → Go engine handoff. Execution model handles this now. |
| Engine routes in `api.php` | The `internal.*` and `jobs.*` route groups. |
| `services.engine.*` config | Engine URL, partition count, stream maxlen config values. |
| Redis `engine` connection | If only used for Go engine communication. |

---

## 13. Execution Flow (Walkthrough)

### Example Workflow

```
Webhook Trigger → HTTP Call A → IF condition
                                  ├─ true  → HTTP B ─┐
                                  └─ false → HTTP C ─┤
                                                     └→ Merge → Transform → HTTP D
```

### Step-by-Step Execution

```
1. POST /api/v1/webhook/{uuid} hits WebhookReceiverController
2. WebhookService::handleIncoming() → ExecutionService::trigger()
3. Execution record created (status: pending)
4. ExecuteWorkflowJob dispatched to engine-high queue

--- Queue worker picks up the job ---

5. ExecuteWorkflowJob::handle() → WorkflowEngine::run($execution)
6. GraphCompiler loads cached WorkflowGraph (or compiles from WorkflowVersion JSON)
7. RunContext initialised: frontier = [trigger], inDegree = {httpA: 1, condition: 1, ...}
8. Execution marked as Running, SSE event published

ITERATION 1 — frontier: [trigger]
  → SyncRunner executes TriggerNode (extracts trigger_data)
  → RunContext::complete('trigger') → decrements httpA's inDegree to 0
  → frontier: [httpA]

ITERATION 2 — frontier: [httpA]
  → AsyncRunner fires HTTP call A via Amp
  → 180ms later, response received
  → RunContext::complete('httpA') → decrements condition's inDegree to 0
  → frontier: [condition]

ITERATION 3 — frontier: [condition]
  → SyncRunner executes ConditionNode
  → Evaluates to: both branches active (condition returns activeBranch: ['true', 'false'])
  → RunContext::complete('condition') → httpB and httpC inDegree both reach 0
  → frontier: [httpB, httpC]

ITERATION 4 — frontier: [httpB, httpC]
  → AsyncRunner fires BOTH HTTP calls concurrently via Amp
  → httpB completes in 120ms, httpC completes in 200ms
  → Both run simultaneously — no sequential waiting
  → RunContext::complete('httpB'), RunContext::complete('httpC')
  → merge node's inDegree was 2, now reaches 0
  → frontier: [merge]
  → BatchWriter threshold hit (5 nodes) → single DB::upsert() for all 5 node results

ITERATION 5 — frontier: [merge]
  → SyncRunner executes MergeNode (combines httpB + httpC outputs)
  → frontier: [transform]

ITERATION 6 — frontier: [transform]
  → SyncRunner executes TransformNode
  → ExpressionParser resolves {{ $nodes.merge.output.combined }} via array access
  → frontier: [httpD]

ITERATION 7 — frontier: [httpD]
  → AsyncRunner fires final HTTP call
  → RunContext::complete('httpD') → no more successors
  → frontier: empty, all nodes done

9. BatchWriter final flush → 1 DB::upsert() for remaining 3 nodes
10. Execution marked as Completed
11. SSE event: execution.completed published
12. CalculateCreditsJob dispatched to engine-post queue
13. $workflow->increment('execution_count') — atomic, no COUNT(*)

TOTAL:
  - DB writes:  2 batched upserts (not 7 individual writes)
  - Queue jobs:  1 (not 7)
  - HTTP B + C:  concurrent (saved ~200ms vs sequential)
  - Wall time:   ~500ms (vs ~700ms sequential + queue overhead)
```

---

## 14. Performance Optimisations Summary

| Optimisation | Before (Go Engine) | After (Native Engine) | Impact |
|---|---|---|---|
| **Graph parsing** | Re-parse JSON every execution | Compiled + cached `WorkflowGraph` | ~5ms saved per execution |
| **Expression resolution** | String parsing at runtime | Pre-compiled token arrays | ~10x faster per field |
| **Node DB writes** | N individual `updateOrCreate` calls | 1-3 batched `upsert()` calls | ~80% fewer DB queries |
| **HTTP concurrency** | Go goroutines (good) | Amp Fibers (equivalent) | Parity |
| **Inter-service latency** | Redis Stream → Go → HTTP callback | Direct in-process call | ~50-100ms saved per execution |
| **Credential fetching** | HTTP call from Go → Laravel API | Direct `Credential::find()` + decrypt | ~20ms saved per node |
| **Workflow stats** | `COUNT(*)` over all executions | `$workflow->increment()` atomic | O(1) vs O(n) |
| **Credit metering** | Inline in callback handler | Async job on `engine-post` queue | Hot path freed |
| **Cancel signal** | Redis Stream publish + Go polling | Redis flag check per iteration | Instant response |
| **Memory** | Go managed (good) | Output projection + ref-count + spill | Controlled |

---

## 15. New Dependencies

```bash
# Only one new package required
composer require amphp/http-client:^5.0
```

This brings in:
- `amphp/amp` v3 — Fiber-based async runtime
- `amphp/byte-stream` — Streaming I/O
- `amphp/socket` — Connection pooling, DNS caching
- `amphp/http-client` — Async HTTP with keep-alive, retries

**Optional** (recommended for production):
```bash
# Queue monitoring dashboard
composer require laravel/horizon

# APCu for in-worker plan caching (if not already installed)
# Usually available via PHP extension, no Composer package needed
```

---

## 16. Implementation Phases

### Phase 1 — Core Engine Skeleton *(~3-4 days)*

**Build**:
- `WorkflowGraph`, `GraphCompiler` (DAG compilation, cycle detection, topological sort)
- `RunContext` (frontier scheduler, in-degree tracking)
- `WorkflowEngine` (frontier loop — sync-only for now)
- `SyncRunner`, `NodePayload`, `NodeResult`
- `TriggerNode`, `TransformNode` (simplest handlers)
- `BatchWriter` (batched upsert)
- `NodeType` enum

**Wire**:
- Rewrite `ExecuteWorkflowJob::handle()` to call `WorkflowEngine::run()`

**Test**:
- Execute a simple 3-node workflow: Trigger → Transform → Transform
- Verify ExecutionNode rows are created via batched upsert
- Verify execution completes with correct status

### Phase 2 — Async HTTP *(~3-4 days)*

**Build**:
- `AsyncRunner` with `amphp/http-client`
- `HttpRequestNode` (full handler: methods, auth, headers, body, timeout, retry)
- `ConnectionLimiter` (per-host concurrency)
- `CredentialStore` (replaces `InternalEngineController`)
- `VariableStore`

**Test**:
- Execute workflows with parallel HTTP branches
- Verify concurrent execution (timing should show parallel, not sequential)
- Verify credential resolution works without HTTP callback

### Phase 3 — Branching & Merging *(~2-3 days)*

**Build**:
- `ConditionNode` (if/switch with branch selection)
- `MergeNode` (multi-predecessor join)
- Branch-aware frontier advancement in `WorkflowEngine`

**Test**:
- IF → branch A / branch B → Merge workflows
- Verify only active branches execute
- Verify merge waits for all predecessors

### Phase 4 — Expression Engine *(~2-3 days)*

**Build**:
- `ExpressionParser` (compile-time tokenisation)
- Integration into `GraphCompiler` (pre-parse all node config templates)
- Runtime resolution in `WorkflowEngine` (token-based array access)

**Test**:
- Workflows where nodes reference upstream outputs
- Complex nested expressions: `{{ $nodes.http.output.body.data[0].name }}`
- Variable references: `{{ $vars.api_key }}`

### Phase 5 — Loops, Delays, Sub-Workflows *(~3-4 days)*

**Build**:
- `LoopNode` (iteration with `node_run_key` generation)
- `DelayNode` (short sleep + long checkpoint/requeue)
- `SubWorkflowNode` (inline frame execution)
- `SetVariableNode`

**Schema**:
- Add `node_run_key`, `loop_index`, `parent_frame` to `execution_nodes`

**Test**:
- Loop over array → HTTP call per item
- Delay node → verify checkpoint + resume
- Sub-workflow execution

### Phase 6 — Checkpointing & Resume *(~2-3 days)*

**Build**:
- `Checkpoint` (serialise/deserialise `RunContext` to Redis + DB)
- `ResumeWorkflowJob`
- `WorkerBudget` (time-slice + memory monitoring)
- `WorkflowEngine::resume()` method

**Schema**:
- Create `execution_checkpoints` table

**Test**:
- Long delay → checkpoint → resume → complete
- Worker budget exceeded → requeue → complete
- Crash recovery from durable checkpoint

### Phase 7 — Production Hardening *(~3-5 days)*

**Build**:
- Horizon configuration
- `WorkerBudget` tuning
- `OutputBuffer` with spill-to-disk for large payloads
- Cancel flag checking per iteration
- Error workflow triggering (`error_workflow_id`)
- Replay pack capture in `HttpRequestNode`
- SSE progress events per node

**Migrate**:
- Off-load credit metering → `CalculateCreditsJob`
- Off-load workflow stats → atomic `increment()`
- Update `ExecutionService::cancel()` to use Redis flag

**Remove**:
- `JobCallbackController`
- `InternalEngineController`
- `EngineDashboardController`
- `EngineHealthService`
- `engine.signature` middleware
- `JobStatus` model
- Engine routes from `api.php`

**Test**:
- Load test: 100 concurrent executions
- Memory profiling on large workflows (50+ nodes)
- Verify no N+1 queries in the hot path

---

## 17. Risks & Guardrails

| Risk | Mitigation |
|---|---|
| **Memory leaks in long-lived workers** | Horizon `maxJobs: 500`, `maxTime: 3600`. Recreate Amp HTTP client per worker lifecycle. |
| **Duplicate side effects on crash/retry** | Generate per-node idempotency keys. Checkpoint before side-effecting nodes. |
| **Loop iterations overwrite node data** | `node_run_key` column ensures each iteration has a unique row. |
| **External API overwhelm** | `ConnectionLimiter` with Redis-backed semaphores. Per-host and per-provider caps. |
| **Worker starvation by long workflows** | `WorkerBudget` enforces 60s time-slice. Checkpoint + requeue for long-running executions. |
| **Redis checkpoint loss** | Hot checkpoint in Redis (fast) + durable checkpoint in DB (on explicit suspend). |
| **Large payload OOM** | `OutputBuffer` spills outputs > 256KB to disk. Ref-count eviction for consumed outputs. |
| **Noisy-neighbour workspaces** | Per-workspace concurrency limits. Separate queue priorities. |
| **Expression injection** | No `eval()`. Token-based array access only. Compiled expressions are data, not code. |

---

## Summary

This engine eliminates the Go service, removes inter-service latency, and matches Go's concurrency via Amp Fibers — all within Laravel's queue worker model. The key performance wins come from: compiled workflow plans, concurrent HTTP via Amp, batched DB writes, smart memory management, and keeping non-critical work off the hot path.

**Estimated total timeline**: 4-6 weeks for full implementation with production hardening.

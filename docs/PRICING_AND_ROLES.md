# Pricing, Credits, Roles & Permissions
## LinkFlow — System Design Document

---

## The Core Philosophy

Three simple rules that govern everything:

1. **Credits** = the only currency. Every plan gives a monthly bucket. You spend credits when workflows run.
2. **Plans** = define what you get (credit bucket) and what you can access (features).
3. **Roles** = define what a user can do inside a workspace.

These three things are **completely independent** of each other:
- A user's role does not change based on the plan.
- A plan does not grant or restrict user actions — it gates workspace-level features.
- Credits are consumed by the workspace, not by individual users.

---

## Part 1 — Credits

### What is a Credit?

A credit is the unit of work. One credit = one node that executes inside a workflow run.

Different node types cost different amounts because they have different real costs:

| Node Type         | Cost       | Why                                      |
|-------------------|------------|------------------------------------------|
| Regular node      | 1 credit   | Standard action (HTTP call, transform)   |
| Code node         | 2 credits  | Sandboxed code execution overhead        |
| AI node           | 10 credits | LLM API calls are expensive              |
| Trigger node      | 0 credits  | Triggers are free — don't punish polling |
| Failed execution  | 0 credits  | Don't punish users for failures          |

### Credit Rules

- Credits reset on the **billing anniversary** every month (not calendar month).
- Credits **do not roll over** on monthly plans — use them or lose them.
- Credits **partially roll over** on annual plans — up to 50% of unused credits carry to next month.
- Extra credits can be purchased as **Credit Packs** any time — they expire after 12 months.
- Credit Packs are consumed **after** the monthly plan credits are exhausted (FIFO).
- Enterprise plan has **unlimited credits** — no tracking needed.

### Credit Consumption Flow

```
Workflow triggered
  → Check: does workspace have enough credits? (Redis fast check)
  → If no → block execution → return 402 with credits_remaining
  → If yes → allow execution
  → ExecuteWorkflowJob dispatches the native WorkflowEngine
  → Execution completes
  → Count nodes by type → calculate total cost
  → Deduct from Redis immediately (atomic)
  → Persist transaction to DB asynchronously
  → Update execution.credits_consumed
```

### Credit Sources (Priority Order)

When consuming credits, drain in this order:
1. Monthly plan credits (from current usage period)
2. Rolled-over credits from previous period
3. Credit packs (oldest expiry first — FIFO)

---

## Part 2 — Plans

### The 5 Plans

| | Free | Starter | Pro | Teams | Enterprise |
|---|---|---|---|---|---|
| **Price/month** | $0 | $12 | $29 | $79 | Custom |
| **Price/year** | $0 | $99 | $249 | $699 | Custom |
| **Credits/month** | 1,000 | 10,000 | 50,000 | 200,000 | Unlimited |
| **Members** | 1 | 3 | 5 | 25 | Unlimited |
| **Active workflows** | 5 | 20 | 100 | Unlimited | Unlimited |
| **Execution log retention** | 3 days | 7 days | 30 days | 90 days | 1 year |
| **Max execution time** | 30s | 120s | 300s | 600s | Custom |
| **Min schedule interval** | — | 15 min | 5 min | 1 min | 1 min |
| **API rate limit/min** | 30 | 60 | 120 | 300 | Custom |
| **Credit packs** | — | ✓ | ✓ | ✓ | ✓ |
| **Annual rollover** | — | — | ✓ | ✓ | ✓ |

### Feature Flags Per Plan

| Feature | Free | Starter | Pro | Teams | Enterprise |
|---|---|---|---|---|---|
| Webhook triggers | — | ✓ | ✓ | ✓ | ✓ |
| Schedule triggers | — | ✓ | ✓ | ✓ | ✓ |
| Import / Export | — | ✓ | ✓ | ✓ | ✓ |
| Custom variables | — | — | ✓ | ✓ | ✓ |
| AI workflow generator | — | — | ✓ | ✓ | ✓ |
| AI auto-fix | — | — | ✓ | ✓ | ✓ |
| Deterministic replay | — | — | ✓ | ✓ | ✓ |
| Execution debugger | — | — | ✓ | ✓ | ✓ |
| Priority execution queue | — | — | — | ✓ | ✓ |
| Environments (staging/prod) | — | — | — | ✓ | ✓ |
| Approval workflows | — | — | — | ✓ | ✓ |

| Connector reliability metrics | — | — | — | ✓ | ✓ |
| Overage protection | — | — | — | — | ✓ |
| Audit logs | — | — | — | — | ✓ |
| SSO / SAML | — | — | — | — | ✓ |

### How Plans Are Stored

Each plan row has two JSON columns:

**`limits` — numeric quotas** (`null` or `-1` = unlimited):
```json
{
  "credits_monthly": 1000,
  "members": 1,
  "active_workflows": 5,
  "max_execution_time_seconds": 30,
  "execution_log_retention_days": 3,
  "min_schedule_interval_minutes": null,
  "api_rate_limit_per_minute": 30
}
```

**`features` — boolean flags**:
```json
{
  "webhook_triggers": false,
  "schedule_triggers": false,
  "import_export": false,
  "custom_variables": false,
  "ai_generation": false,
  "ai_autofix": false,
  "deterministic_replay": false,
  "execution_debugger": false,
  "priority_execution": false,
  "environments": false,
  "approval_workflows": false,

  "connector_metrics": false,
  "overage_protection": false,
  "audit_logs": false,
  "sso_saml": false,
  "annual_rollover": false,
  "credit_packs": false
}
```

> The plan's JSON is the **single source of truth**. No hardcoded feature maps anywhere in code.

---

## Part 3 — Roles & Permissions

### System Roles

Four built-in roles. Defined as a PHP enum. Permissions are hardcoded inside the enum. No custom roles.

#### Owner
- Full access to everything including billing management.
- Only one Owner per workspace.
- Cannot be assigned — automatically set on workspace creation.
- Cannot be removed — must transfer ownership first.

#### Admin
- Everything except: billing management, workspace deletion, ownership transfer.
- Can manage members (invite, change role, remove).
- Can manage all workspace resources (workflows, credentials, webhooks, variables, environments).

#### Member
- Can create and edit resources but **cannot delete** them.
- Cannot manage other members.
- Cannot manage billing.
- Cannot delete workflows, credentials, webhooks, variables, or tags.

#### Viewer
- Read-only access to everything.
- Cannot create, edit, or delete anything.
- Cannot trigger executions.
- Cannot manage members or billing.

### Permission Matrix — System Roles

| Permission | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| **Workspace** | | | | |
| workspace.view | ✓ | ✓ | ✓ | ✓ |
| workspace.update | ✓ | ✓ | — | — |
| workspace.delete | ✓ | — | — | — |
| workspace.manage-billing | ✓ | — | — | — |
| **Members** | | | | |
| member.view | ✓ | ✓ | ✓ | ✓ |
| member.invite | ✓ | ✓ | — | — |
| member.update | ✓ | ✓ | — | — |
| member.remove | ✓ | ✓ | — | — |
| **Workflows** | | | | |
| workflow.view | ✓ | ✓ | ✓ | ✓ |
| workflow.create | ✓ | ✓ | ✓ | — |
| workflow.update | ✓ | ✓ | ✓ | — |
| workflow.delete | ✓ | ✓ | — | — |
| workflow.execute | ✓ | ✓ | ✓ | — |
| workflow.activate | ✓ | ✓ | ✓ | — |
| workflow.export | ✓ | ✓ | ✓ | — |
| workflow.import | ✓ | ✓ | ✓ | — |
| **Credentials** | | | | |
| credential.view | ✓ | ✓ | ✓ | ✓ |
| credential.create | ✓ | ✓ | ✓ | — |
| credential.update | ✓ | ✓ | ✓ | — |
| credential.delete | ✓ | ✓ | — | — |
| **Executions** | | | | |
| execution.view | ✓ | ✓ | ✓ | ✓ |
| execution.delete | ✓ | ✓ | — | — |
| **Webhooks** | | | | |
| webhook.view | ✓ | ✓ | ✓ | ✓ |
| webhook.create | ✓ | ✓ | ✓ | — |
| webhook.update | ✓ | ✓ | ✓ | — |
| webhook.delete | ✓ | ✓ | — | — |
| **Tags** | | | | |
| tag.view | ✓ | ✓ | ✓ | ✓ |
| tag.create | ✓ | ✓ | ✓ | — |
| tag.update | ✓ | ✓ | ✓ | — |
| tag.delete | ✓ | ✓ | — | — |
| **Variables** | | | | |
| variable.view | ✓ | ✓ | ✓ | ✓ |
| variable.create | ✓ | ✓ | ✓ | — |
| variable.update | ✓ | ✓ | ✓ | — |
| variable.delete | ✓ | ✓ | — | — |
| **Environments** | | | | |
| environment.view | ✓ | ✓ | ✓ | ✓ |
| environment.create | ✓ | ✓ | — | — |
| environment.update | ✓ | ✓ | — | — |
| environment.delete | ✓ | ✓ | — | — |
| environment.deploy | ✓ | ✓ | — | — |


---

## Part 4 — How It All Works Together

### Request Lifecycle

```
HTTP Request arrives
  ↓
auth:api middleware
  → validate Passport token → get authenticated User
  ↓
resolve.workspace.role middleware (on all workspace routes)
  → load Workspace from route model binding
  → is user the owner?
      YES → permissions = ALL permissions (0 DB queries)
      NO  → load workspace_members pivot
              → load system Role enum → permissions = Role::permissions() (1 DB query)
  → store role + permissions on $request->attributes
  ↓
Controller
  → $this->authorize('create', Workflow::class)
       → WorkflowPolicy::create()
            → ChecksWorkspacePermission::has(Permission::WorkflowCreate)
                 → in_array() against $request->attributes (no DB hit)
  ↓
  → PlanEnforcementService::requireFeature($workspace, 'webhook_triggers')
       → $workspace->hasFeature('webhook_triggers')
            → subscription → plan → features['webhook_triggers']
  ↓
  → PlanEnforcementService::checkCredits($workspace, $estimatedCost)
       → CreditMeterService::getAvailable($workspace)
            → Redis::get("credits:available:{$workspaceId}")
```

### Two Checks, Always Separate

```
Before ANY action:
  1. Can this USER do this? → Role/Permission check
  2. Can this WORKSPACE do this? → Plan/Feature check

Never merge these. A user can have permission to create a webhook
but the workspace plan might not include webhooks.
Both must pass.
```

### Where Each Check Happens

| Action | Permission Check | Plan Check |
|---|---|---|
| Create workflow | workflow.create | — |
| Activate workflow | workflow.activate | checkActiveWorkflows() limit |
| Create webhook trigger | webhook.create | requireFeature('webhook_triggers') |
| Create schedule trigger | workflow.create | requireFeature('schedule_triggers') |
| Trigger execution | workflow.execute | checkCredits() |
| Create variable | variable.create | requireFeature('custom_variables') |
| Invite member | member.invite | checkMembers() limit |
| Create environment | environment.create | requireFeature('environments') |
| Create approval node | workflow.create | requireFeature('approval_workflows') |

| Generate AI workflow | workflow.create | requireFeature('ai_generation') + checkCredits(10) |
| Run AI auto-fix | workflow.update | requireFeature('ai_autofix') |
| Export workflows | workflow.export | requireFeature('import_export') |
| Purchase credit pack | workspace.manage-billing | requireFeature('credit_packs') |

---

## Part 5 — Database Tables

### `plans`
```
id
name                  string        "Pro"
slug                  string unique "pro"
description           text nullable
price_monthly         int           cents (2900 = $29)
price_yearly          int           cents (24900 = $249)
limits                json          numeric quotas
features              json          boolean feature flags
stripe_product_id     string nullable
stripe_prices         json          { monthly: "price_xxx", yearly: "price_yyy" }
is_active             bool
sort_order            int
timestamps
```

### `subscriptions`
```
id
workspace_id          FK → workspaces (unique — one subscription per workspace)
plan_id               FK → plans
stripe_subscription_id string nullable
stripe_customer_id     string nullable
stripe_price_id        string nullable
status                 enum: active|trialing|past_due|canceled|expired
billing_interval       enum: monthly|yearly
credits_monthly        int           snapshot of plan credits at subscribe time
trial_ends_at          timestamp nullable
current_period_start   timestamp nullable
current_period_end     timestamp nullable
canceled_at            timestamp nullable
timestamps
```

### `workspace_usage_periods`
```
id
workspace_id          FK → workspaces
subscription_id       FK → subscriptions nullable
period_start          date
period_end            date
credits_limit         int           from plan at period start
credits_from_packs    int default 0 added via credit packs this period
credits_rolled_over   int default 0 carried from previous period
credits_used          int default 0 consumed this period
credits_overage       int default 0 used beyond limit (enterprise only)
executions_total      int default 0
executions_succeeded  int default 0
executions_failed     int default 0
nodes_executed        int default 0
ai_nodes_executed     int default 0
is_current            bool default false
is_overage_billed     bool default false
stripe_invoice_id     string nullable
timestamps
UNIQUE(workspace_id, period_start)
INDEX(workspace_id, is_current)
```

### `credit_transactions`
```
id
workspace_id          FK → workspaces
usage_period_id       FK → workspace_usage_periods
type                  enum: execution|ai_execution|code_execution|
                            refund|adjustment|pack_purchase|bonus|rollover
credits               int   positive = consumption, negative = credit to user
description           string nullable
execution_id          FK → executions nullable
execution_node_id     FK → execution_nodes nullable
created_at            timestamp
INDEX(workspace_id, created_at)
INDEX(usage_period_id, type)
```

### `credit_packs`
```
id
workspace_id          FK → workspaces
purchased_by          FK → users
credits_amount        int           total credits in pack
credits_remaining     int           remaining to consume
price_cents           int
currency              string default 'usd'
stripe_payment_intent_id string nullable
status                enum: pending|active|exhausted|expired|refunded
purchased_at          timestamp
expires_at            timestamp nullable  (12 months from purchase)
timestamps
INDEX(workspace_id, status)
INDEX(workspace_id, expires_at)
```

### `workspace_members`
```
id
workspace_id          FK → workspaces
user_id               FK → users
role                  string default 'member'   system role (owner|admin|member|viewer)
joined_at             timestamp
timestamps
UNIQUE(workspace_id, user_id)
INDEX(workspace_id, role)
```

---

## Part 6 — Key Services

### `CreditMeterService`

Responsible for all credit operations. Uses Redis as the hot path, DB as the source of truth.

**Redis keys:**
```
credits:available:{workspaceId}   → remaining credits (int)
```

**Methods:**
```
getAvailable(Workspace)     → int   (Redis → DB fallback)
consume(Execution, nodes)   → int   (deduct Redis, async persist DB)
refund(Execution)           → void  (add back on failure)
addPackCredits(CreditPack)  → void  (add pack to available pool)
rolloverPeriod(Workspace)   → void  (close period, carry unused, open new)
calculateCost(nodes[])      → int   (regular×1 + code×2 + ai×10)
```

### `PlanEnforcementService`

Single place for all plan checks. Always throws typed exceptions — never returns bool.

**Methods:**
```
checkCredits(Workspace, int $needed)         throws InsufficientCreditsException
requireFeature(Workspace, string $feature)   throws FeatureNotAvailableException
checkActiveWorkflows(Workspace)              throws QuotaExceededException
checkMembers(Workspace)                      throws QuotaExceededException
checkScheduleInterval(Workspace, int $mins)  throws PlanLimitException
getMaxExecutionTime(Workspace)               returns int (seconds)
getLogRetentionDays(Workspace)               returns int
getExecutionPriority(Workspace)              returns string (queue name)
getRateLimitPerMinute(Workspace)             returns int
```

---

## Part 7 — Scheduled Jobs

```
Daily at 00:05    SnapshotDailyUsage      aggregate yesterday's usage per workspace
Daily at 00:10    ExpireCreditPacks       mark expired packs → status: expired
Every 5 min       TimeoutStaleExecutions  mark stuck running → failed
On period end     ResetMonthlyCredits     rollover + create new usage period + reset Redis
```

---

## Part 8 — Custom Exceptions

```
Plan/
  InsufficientCreditsException    credits_needed, credits_available → 402
  FeatureNotAvailableException    feature_key, required_plan       → 403
  QuotaExceededException          quota_key, limit                 → 403
  PlanLimitException              limit_key, limit_value           → 403

Workspace/
  NotAMemberException                                              → 403
  MemberLimitReachedException                                      → 403


```

---

## Part 9 — File Structure

```
app/
  Enums/
    Role.php                        Owner | Admin | Member | Viewer
    Permission.php                  All permission cases
    SubscriptionStatus.php          Active | Trialing | PastDue | Canceled | Expired
    CreditTransactionType.php       Execution | AiExecution | CodeExecution |
                                    Refund | Adjustment | PackPurchase | Bonus | Rollover

  Models/
    Plan.php                        getLimit(), hasFeature()
    Subscription.php                isActive(), isUsable(), onTrial()
    WorkspaceUsagePeriod.php        totalAvailable(), creditsRemaining(), isExhausted()
    CreditTransaction.php
    CreditPack.php                  isUsable(), consume()
    WorkspaceMember.php             (pivot as full model)

  Services/
    CreditMeterService.php
    PlanEnforcementService.php

  Exceptions/
    Plan/
      InsufficientCreditsException.php
      FeatureNotAvailableException.php
      QuotaExceededException.php
      PlanLimitException.php
    Workspace/
      NotAMemberException.php

  Http/
    Middleware/
      ResolveWorkspaceRole.php
    Controllers/Api/V1/
      CreditController.php          balance, history, purchase pack
    Resources/Api/V1/
      CreditBalanceResource.php
      CreditTransactionResource.php

  Console/Commands/
    Billing/
      ResetMonthlyCredits.php
      ExpireCreditPacks.php
      SnapshotDailyUsage.php
```

---

## Part 10 — The Golden Rules

1. **Credits are workspace-level, not user-level.** One workspace = one credit pool.
2. **Plan gates features. Roles gate actions.** Never mix them.
3. **Redis is truth for speed. DB is truth for accuracy.** Always reconcile if they diverge.
4. **Failed executions never cost credits.** Refund on failure.
5. **Owner is never stored in workspace_members.** Resolved from `workspace.owner_id`.
6. **One subscription per workspace.** Unique constraint on `subscriptions.workspace_id`.
7. **Credit packs drain after plan credits.** Never mix the two pools — track separately.
8. **Four system roles only.** Owner, Admin, Member, Viewer — hardcoded in enum, no custom roles.

# LinkFlow — Pricing & Credits Implementation Guide

## Overview

LinkFlow uses a **credit-based pricing system** where every workflow execution consumes credits from a workspace's monthly bucket. The system is built on three independent pillars:

1. **Credits** — The currency. Workspaces spend credits when workflows run.
2. **Plans** — Define credit buckets and feature access.
3. **Roles** — Define what users can do inside a workspace (independent of plans).

---

## Plans & Pricing

### The 5 Plans

| Plan | Monthly | Yearly | Credits/mo | Members | Active Workflows |
|------|---------|--------|------------|---------|-----------------|
| **Free** | $0 | $0 | 1,000 | 1 | 5 |
| **Starter** | $12 | $99/yr | 10,000 | 3 | 20 |
| **Pro** | $29 | $249/yr | 50,000 | 5 | 100 |
| **Teams** | $79 | $699/yr | 200,000 | 25 | Unlimited |
| **Enterprise** | Custom | Custom | Unlimited | Unlimited | Unlimited |

### Feature Access by Plan

| Feature | Free | Starter | Pro | Teams | Enterprise |
|---------|------|---------|-----|-------|------------|
| Webhook triggers | — | ✓ | ✓ | ✓ | ✓ |
| Schedule triggers | — | ✓ | ✓ | ✓ | ✓ |
| Import / Export | — | ✓ | ✓ | ✓ | ✓ |
| Credit packs | — | ✓ | ✓ | ✓ | ✓ |
| Custom variables | — | — | ✓ | ✓ | ✓ |
| AI generation | — | — | ✓ | ✓ | ✓ |
| AI auto-fix | — | — | ✓ | ✓ | ✓ |
| Deterministic replay | — | — | ✓ | ✓ | ✓ |
| Execution debugger | — | — | ✓ | ✓ | ✓ |
| Annual rollover | — | — | ✓ | ✓ | ✓ |
| Priority execution queue | — | — | — | ✓ | ✓ |
| Environments (staging/prod) | — | — | — | ✓ | ✓ |
| Approval workflows | — | — | — | ✓ | ✓ |
| Connector metrics | — | — | — | ✓ | ✓ |
| Overage protection | — | — | — | — | ✓ |
| Audit logs | — | — | — | — | ✓ |
| SSO / SAML | — | — | — | — | ✓ |

### Plan Limits

| Limit | Free | Starter | Pro | Teams | Enterprise |
|-------|------|---------|-----|-------|------------|
| Execution log retention | 3 days | 7 days | 30 days | 90 days | 365 days |
| Max execution time | 30s | 120s | 300s | 600s | Custom |
| Min schedule interval | — | 15 min | 5 min | 1 min | 1 min |
| API rate limit/min | 30 | 60 | 120 | 300 | Custom |

---

## How Credits Work

### Credit Costs per Node Type

| Node Type | Cost | Reason |
|-----------|------|--------|
| **Trigger node** (`trigger_*`) | 0 credits | Free — don't punish polling |
| **Regular node** (HTTP, logic, etc.) | 1 credit | Standard action |
| **Code node** (`action_transform`, `code_*`) | 2 credits | Sandboxed execution overhead |
| **AI node** (`ai_*`) | 10 credits | LLM API calls are expensive |
| **Failed execution** | 0 credits | Don't punish users for failures |

### Credit Rules

- Credits reset on the **billing anniversary** (not calendar month)
- Monthly plans: unused credits **do not roll over**
- Yearly plans with `annual_rollover` feature: **50% of unused credits** carry to next month
- Credit packs can be purchased anytime — expire after 12 months
- Credit packs are consumed **after** monthly plan credits (FIFO by expiry)
- Enterprise plan has **unlimited credits**

### Credit Consumption Flow

```
Workflow triggered
  → PlanEnforcementService::checkCredits() — Redis fast check
  → If insufficient → block execution → 402 with credits_remaining
  → If sufficient → allow execution
  → Go engine runs workflow
  → Engine sends callback to JobCallbackController
  → Nodes upserted, execution status updated
  → If completed:
      → CreditMeterService::consume() calculates cost from nodes
      → Deducts from Redis atomically
      → Creates CreditTransaction in DB
      → Updates WorkspaceUsagePeriod.credits_used
      → Sets Execution.credits_consumed
  → If failed/cancelled: credits_consumed = 0
```

### Credit Source Priority (Drain Order)

1. Monthly plan credits (current usage period)
2. Rolled-over credits from previous period
3. Credit packs (oldest expiry first — FIFO)

---

## Implementation Architecture

### Core Services

#### `CreditMeterService` (`app/Services/CreditMeterService.php`)

All credit operations. Redis as hot path, DB as source of truth.

| Method | Purpose |
|--------|---------|
| `getAvailable(Workspace)` | Returns available credits (Redis → DB fallback) |
| `getAvailableFromDatabase(Workspace)` | Calculates from DB (period remaining + pack credits) |
| `calculateCost(ExecutionNode[])` | Sums credit cost by node type |
| `consume(Execution, nodes)` | Charges credits (idempotent — won't double-charge) |
| `refund(Execution)` | Refunds credits back to workspace |
| `addPackCredits(CreditPack)` | Adds purchased pack credits to pool |
| `rolloverPeriod(Workspace)` | Closes period, carries over unused (if eligible), creates new period |
| `drainPacks(Workspace, amount)` | Drains packs FIFO by expiry date |
| `syncRedisBalance(Workspace)` | Reconciles Redis from DB |

**Redis key:** `credits:available:{workspaceId}` → remaining credits (int)

#### `PlanEnforcementService` (`app/Services/PlanEnforcementService.php`)

All plan checks. Always throws typed exceptions — never returns bool.

| Method | Throws | When |
|--------|--------|------|
| `checkCredits(Workspace, int)` | `InsufficientCreditsException` | Insufficient credits |
| `requireFeature(Workspace, string)` | `FeatureNotAvailableException` | Feature not on plan |
| `checkActiveWorkflows(Workspace)` | `QuotaExceededException` | Too many active workflows |
| `checkMembers(Workspace)` | `QuotaExceededException` | Member limit reached |
| `checkScheduleInterval(Workspace, int)` | `PlanLimitException` | Interval below minimum |
| `getMaxExecutionTime(Workspace)` | — | Returns seconds |
| `getLogRetentionDays(Workspace)` | — | Returns days |
| `getExecutionPriority(Workspace)` | — | Returns `'normal'` or `'high'` |
| `getRateLimitPerMinute(Workspace)` | — | Returns rate limit |

### Custom Exceptions

| Exception | HTTP Status | When |
|-----------|-------------|------|
| `InsufficientCreditsException` | 402 | Not enough credits |
| `FeatureNotAvailableException` | 403 | Feature not on plan |
| `QuotaExceededException` | 403 | Quota limit reached |
| `PlanLimitException` | 403 | Plan-based limit exceeded |

### API Endpoints

#### Credit Balance
```
GET /api/v1/workspaces/{workspace}/credits/balance
```

Response:
```json
{
  "data": {
    "workspace_id": 1,
    "plan": {
      "name": "Pro",
      "slug": "pro"
    },
    "billing_interval": "monthly",
    "credits": {
      "limit": 50000,
      "used": 12500,
      "remaining": 37500,
      "from_packs": 0,
      "rolled_over": 0
    },
    "period": {
      "start": "2026-03-01",
      "end": "2026-03-31"
    }
  }
}
```

#### Credit Transactions
```
GET /api/v1/workspaces/{workspace}/credits/transactions
```

Response (paginated):
```json
{
  "data": [
    {
      "id": 1,
      "type": "execution",
      "credits": 14,
      "description": "Execution #42",
      "execution_id": 42,
      "created_at": "2026-03-07T10:30:00.000Z"
    }
  ],
  "links": { "..." },
  "meta": { "..." }
}
```

### Scheduled Billing Jobs

| Schedule | Command | Purpose |
|----------|---------|---------|
| Daily 00:05 | `billing:snapshot-daily-usage` | Aggregates yesterday's usage per workspace |
| Daily 00:10 | `billing:expire-credit-packs` | Marks expired packs → `expired` status |
| Daily | `billing:reset-monthly-credits` | Rolls over periods that have ended, creates new period, resets Redis |

### Database Tables

| Table | Purpose |
|-------|---------|
| `plans` | Plan definitions with JSON `limits` and `features` columns |
| `subscriptions` | One per workspace — links to plan, stores billing state |
| `workspace_usage_periods` | Monthly/yearly credit buckets with usage tracking |
| `credit_transactions` | Ledger of all credit changes (execution, refund, pack, rollover) |
| `credit_packs` | Purchased credit bundles with expiry dates |
| `usage_daily_snapshots` | Daily aggregated usage for analytics |

### Workspace Billing Bootstrap

When a new workspace is created (`WorkspaceService::create()`):

1. Workspace created with `owner_id`
2. Owner added to `workspace_members` with `owner` role
3. Free plan looked up from DB
4. `Subscription` created (status: active, billing_interval: monthly)
5. `WorkspaceUsagePeriod` created (is_current: true, 30-day period)
6. Redis key `credits:available:{id}` initialized with plan credits

---

## How Plan Checks Work in Practice

### Two Checks, Always Separate

Before ANY action:
1. **Can this USER do this?** → Role/Permission check (middleware + policy)
2. **Can this WORKSPACE do this?** → Plan/Feature check (PlanEnforcementService)

Never merge these. A user can have permission to create a webhook, but the workspace plan might not include webhooks. Both must pass.

### Where Each Check Happens

| Action | Permission Check | Plan Check |
|--------|-----------------|------------|
| Create workflow | `workflow.create` | — |
| Activate workflow | `workflow.activate` | `checkActiveWorkflows()` |
| Create webhook trigger | `webhook.create` | `requireFeature('webhook_triggers')` |
| Trigger execution | `workflow.execute` | `checkCredits()` |
| Create variable | `variable.create` | `requireFeature('custom_variables')` |
| Invite member | `member.invite` | `checkMembers()` |
| Create environment | `environment.create` | `requireFeature('environments')` |
| AI workflow generator | `workflow.create` | `requireFeature('ai_generation')` + `checkCredits(10)` |
| Purchase credit pack | `workspace.manage-billing` | `requireFeature('credit_packs')` |

---

## Unlimited Handling

- Plan limits use `-1` or `null` to represent unlimited
- `PlanEnforcementService` treats `-1`/`null` as unlimited — never throws
- `CreditMeterService` returns `PHP_INT_MAX` for unlimited credit balance
- Enterprise plan has all features enabled and all limits set to `-1`

---

## Idempotency Guarantees

- **Credit charging is idempotent**: If `execution.credits_consumed` is already set, `consume()` returns the existing value without re-charging
- **Engine callbacks are idempotent**: If `JobStatus` is already terminal, the callback returns success without DB writes
- **Daily snapshots are idempotent**: Skips if a snapshot already exists for the workspace + date

---

## Test Coverage

| Test File | Tests | What's Covered |
|-----------|-------|----------------|
| `tests/Feature/PlanEnforcementTest.php` | 29 | All enforcement methods, unlimited handling, exceptions |
| `tests/Feature/CreditMeterTest.php` | 30 | All credit operations, FIFO drain, rollover, idempotency |
| `tests/Feature/WorkspaceServiceTest.php` | 5 | Billing bootstrap on workspace creation |
| `tests/Feature/CreditApiTest.php` | 9 | API endpoints for balance and transactions |
| `tests/Feature/BillingCommandsTest.php` | 6 | All 3 billing commands |
| `tests/Feature/Models/PlanTest.php` | (existing) | Plan model methods |

**Total: 269 tests, 624 assertions — all passing**

<?php

namespace App\Services;

use App\Enums\CreditPackStatus;
use App\Enums\CreditTransactionType;
use App\Enums\ExecutionStatus;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CreditMeterService
{
    /**
     * Node type prefix → credit cost mapping.
     *
     * | Node Type      | Cost       |
     * |----------------|------------|
     * | Trigger node   | 0 credits  |
     * | Regular node   | 1 credit   |
     * | Code node      | 2 credits  |
     * | AI node        | 10 credits |
     */
    private const NODE_COST_TRIGGER = 0;

    private const NODE_COST_REGULAR = 1;

    private const NODE_COST_CODE = 2;

    private const NODE_COST_AI = 10;

    /**
     * Get available credits for a workspace.
     * Redis first, DB fallback to current period remaining + usable packs.
     */
    public function getAvailable(Workspace $workspace): int
    {
        try {
            $cached = Redis::get("credits:available:{$workspace->id}");
            if ($cached !== null) {
                return max(0, (int) $cached);
            }
        } catch (\Exception) {
            // Redis not available, fall through to database
        }

        return $this->getAvailableFromDatabase($workspace);
    }

    /**
     * Calculate available credits from database (source of truth).
     */
    public function getAvailableFromDatabase(Workspace $workspace): int
    {
        $period = $workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return 0;
        }

        // Check for unlimited (Enterprise)
        if ($period->credits_limit === -1) {
            return PHP_INT_MAX;
        }

        $periodRemaining = $period->creditsRemaining();

        // Add credits from usable packs (active, not expired, with remaining credits)
        $packCredits = $workspace->creditPacks()
            ->where('status', CreditPackStatus::Active)
            ->where('credits_remaining', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('credits_remaining');

        return $periodRemaining + (int) $packCredits;
    }

    /**
     * Calculate total cost for given execution nodes.
     *
     * @param  array<int, ExecutionNode>  $nodes
     */
    public function calculateCost(array $nodes): int
    {
        $cost = 0;

        foreach ($nodes as $node) {
            if ($node instanceof ExecutionNode) {
                $cost += $this->getNodeCost($node->node_type);
            }
        }

        return $cost;
    }

    /**
     * Consume credits for a completed execution.
     * Idempotent — will not double-charge if called again.
     *
     * Returns the amount actually consumed.
     */
    public function consume(Execution $execution, array $nodes): int
    {
        // Failed/cancelled executions cost nothing
        if (in_array($execution->status, [ExecutionStatus::Failed, ExecutionStatus::Cancelled])) {
            return 0;
        }

        // Idempotency: already charged
        if ($execution->credits_consumed !== null && $execution->credits_consumed > 0) {
            return $execution->credits_consumed;
        }

        $cost = $this->calculateCost($nodes);

        if ($cost === 0) {
            $execution->update(['credits_consumed' => 0]);

            return 0;
        }

        $period = $execution->workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return 0;
        }

        // Atomically deduct from Redis
        try {
            Redis::decrby("credits:available:{$execution->workspace_id}", $cost);
        } catch (\Exception) {
            // Redis not available
        }

        // Count node types for period statistics
        $nodeStats = $this->countNodeTypes($nodes);

        DB::transaction(function () use ($execution, $cost, $period, $nodeStats) {
            CreditTransaction::create([
                'workspace_id' => $execution->workspace_id,
                'usage_period_id' => $period->id,
                'type' => CreditTransactionType::Execution,
                'credits' => $cost,
                'description' => "Execution #{$execution->id}",
                'execution_id' => $execution->id,
                'created_at' => now(),
            ]);

            $period->query()->where('id', $period->id)->update([
                'credits_used' => DB::raw("credits_used + {$cost}"),
                'executions_total' => DB::raw('executions_total + 1'),
                'executions_succeeded' => DB::raw('executions_succeeded + 1'),
                'nodes_executed' => DB::raw("nodes_executed + {$nodeStats['total']}"),
                'ai_nodes_executed' => DB::raw("ai_nodes_executed + {$nodeStats['ai']}"),
            ]);

            $execution->update(['credits_consumed' => $cost]);
        });

        return $cost;
    }

    /**
     * Refund credits for an execution.
     */
    public function refund(Execution $execution): void
    {
        $credits = $execution->credits_consumed ?? 0;

        if ($credits === 0) {
            return;
        }

        $period = $execution->workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return;
        }

        try {
            Redis::incrby("credits:available:{$execution->workspace_id}", $credits);
        } catch (\Exception) {
            // Redis not available
        }

        DB::transaction(function () use ($execution, $credits, $period) {
            CreditTransaction::create([
                'workspace_id' => $execution->workspace_id,
                'usage_period_id' => $period->id,
                'type' => CreditTransactionType::Refund,
                'credits' => -$credits,
                'description' => "Refund for execution #{$execution->id}",
                'execution_id' => $execution->id,
                'created_at' => now(),
            ]);

            $period->decrement('credits_used', min($credits, $period->credits_used));

            $execution->update(['credits_consumed' => 0]);
        });
    }

    /**
     * Add credits from a purchased credit pack.
     */
    public function addPackCredits(CreditPack $pack): void
    {
        $workspace = $pack->workspace;

        $period = $workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return;
        }

        $period->increment('credits_from_packs', $pack->credits_amount);

        try {
            Redis::incrby("credits:available:{$workspace->id}", $pack->credits_amount);
        } catch (\Exception) {
            // Redis not available
        }

        CreditTransaction::create([
            'workspace_id' => $workspace->id,
            'usage_period_id' => $period->id,
            'type' => CreditTransactionType::PackPurchase,
            'credits' => $pack->credits_amount,
            'description' => "Credit pack #{$pack->id}",
            'created_at' => now(),
        ]);
    }

    /**
     * Roll over to next billing period.
     *
     * - Closes current period
     * - If yearly + plan has annual_rollover feature: carry 50% of unused credits
     * - Creates new period
     * - Resets Redis balance
     */
    public function rolloverPeriod(Workspace $workspace): void
    {
        $currentPeriod = $workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $currentPeriod) {
            return;
        }

        DB::transaction(function () use ($workspace, $currentPeriod) {
            $currentPeriod->update(['is_current' => false]);

            $subscription = $currentPeriod->subscription;

            if (! $subscription) {
                return;
            }

            $remaining = $currentPeriod->creditsRemaining();
            $rolledOver = 0;

            // Annual rollover: yearly billing + plan feature flag
            $plan = $subscription->plan;
            $isYearly = $subscription->billing_interval->value === 'yearly';
            $hasRolloverFeature = $plan?->hasFeature('annual_rollover') ?? false;

            // Also check workspace settings as fallback
            $workspaceRollover = $workspace->settings['annual_rollover'] ?? false;

            if ($isYearly && ($hasRolloverFeature || $workspaceRollover) && $remaining > 0) {
                $rolledOver = (int) floor($remaining * 0.5);
            }

            // Calculate new period dates
            $periodStart = $currentPeriod->period_end->addDay();
            $periodEnd = match ($subscription->billing_interval->value) {
                'yearly' => $periodStart->copy()->addYear()->subDay(),
                default => $periodStart->copy()->addMonth()->subDay(),
            };

            $newPeriod = $workspace->usagePeriods()->create([
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'credits_limit' => $subscription->credits_monthly,
                'credits_rolled_over' => $rolledOver,
                'is_current' => true,
            ]);

            if ($rolledOver > 0) {
                CreditTransaction::create([
                    'workspace_id' => $workspace->id,
                    'usage_period_id' => $newPeriod->id,
                    'type' => CreditTransactionType::Rollover,
                    'credits' => $rolledOver,
                    'description' => "Rollover from period #{$currentPeriod->id}",
                    'created_at' => now(),
                ]);
            }

            // Reset Redis
            try {
                $totalAvailable = $newPeriod->credits_limit + $rolledOver;
                Redis::set("credits:available:{$workspace->id}", $totalAvailable);
            } catch (\Exception) {
                // Redis not available
            }
        });
    }

    /**
     * Drain credits from credit packs (oldest expiry first — FIFO).
     * Used after monthly plan credits are exhausted.
     */
    public function drainPacks(Workspace $workspace, int $amount): int
    {
        $drained = 0;

        $packs = $workspace->creditPacks()
            ->where('status', CreditPackStatus::Active)
            ->where('credits_remaining', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('expires_at')
            ->get();

        foreach ($packs as $pack) {
            if ($drained >= $amount) {
                break;
            }

            $toConsume = min($amount - $drained, $pack->credits_remaining);
            $pack->credits_remaining -= $toConsume;

            if ($pack->credits_remaining <= 0) {
                $pack->status = CreditPackStatus::Exhausted;
            }

            $pack->save();
            $drained += $toConsume;
        }

        return $drained;
    }

    /**
     * Sync Redis credit balance from database (reconciliation).
     */
    public function syncRedisBalance(Workspace $workspace): void
    {
        $available = $this->getAvailableFromDatabase($workspace);

        try {
            Redis::set("credits:available:{$workspace->id}", $available);
        } catch (\Exception) {
            // Redis not available
        }
    }

    /**
     * Get credit cost for a node type.
     *
     * Classification by prefix:
     * - trigger_*  → 0 credits (free)
     * - ai_*       → 10 credits
     * - code_*, action_transform → 2 credits
     * - everything else → 1 credit
     */
    private function getNodeCost(string $nodeType): int
    {
        if (str_starts_with($nodeType, 'trigger_')) {
            return self::NODE_COST_TRIGGER;
        }

        if (str_starts_with($nodeType, 'ai_')) {
            return self::NODE_COST_AI;
        }

        if ($nodeType === 'action_transform' || str_starts_with($nodeType, 'code_')) {
            return self::NODE_COST_CODE;
        }

        return self::NODE_COST_REGULAR;
    }

    /**
     * Count node types for period statistics.
     *
     * @param  array<int, ExecutionNode>  $nodes
     * @return array{total: int, ai: int}
     */
    private function countNodeTypes(array $nodes): array
    {
        $total = 0;
        $ai = 0;

        foreach ($nodes as $node) {
            if ($node instanceof ExecutionNode) {
                $total++;
                if (str_starts_with($node->node_type, 'ai_')) {
                    $ai++;
                }
            }
        }

        return ['total' => $total, 'ai' => $ai];
    }
}

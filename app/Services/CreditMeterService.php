<?php

namespace App\Services;

use App\Enums\CreditTransactionType;
use App\Enums\ExecutionStatus;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CreditMeterService
{
    /**
     * Node type to credit cost mapping.
     */
    private const CREDIT_COSTS = [
        'trigger_webhook' => 0,        // Trigger node
        'action_http_request' => 1,    // Regular node
        'action_transform' => 2,       // Code node
        'logic_if' => 1,               // Regular node
        // Default for unknown types
        'default_regular' => 1,
        'default_code' => 2,
        'default_ai' => 10,
    ];

    /**
     * Get available credits for a workspace.
     * Redis first, DB fallback to current period remaining + usable packs.
     */
    public function getAvailable(Workspace $workspace): int
    {
        try {
            $cached = Redis::get("credits:available:{$workspace->id}");
            if ($cached !== null) {
                return (int) $cached;
            }
        } catch (\Exception) {
            // Redis not available, fall through to database
        }

        // Database fallback
        $period = $workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return 0;
        }

        // Calculate remaining in current period
        $remaining = $period->creditsRemaining();

        // Add credits from usable packs
        $packCredits = $workspace->creditPacks()
            ->where('status', 'active')
            ->where('credits_remaining', '>', 0)
            ->sum('credits_remaining');

        return $remaining + $packCredits;
    }

    /**
     * Calculate total cost for given execution nodes.
     */
    public function calculateCost(array $nodes): int
    {
        $cost = 0;

        foreach ($nodes as $node) {
            if ($node instanceof ExecutionNode) {
                $cost += $this->getCreditCostForNodeType($node->node_type);
            }
        }

        return $cost;
    }

    /**
     * Consume credits for an execution with idempotency check.
     * Returns the amount actually consumed.
     */
    public function consume(Execution $execution, array $nodes): int
    {
        // Failed executions cost nothing
        if ($execution->status === ExecutionStatus::Failed) {
            return 0;
        }

        // Idempotency check: already charged?
        if ($execution->credits_consumed !== null) {
            return $execution->credits_consumed;
        }

        $cost = $this->calculateCost($nodes);

        if ($cost === 0) {
            $execution->update(['credits_consumed' => 0]);
            return 0;
        }

        // Get current period
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
            // Redis not available, proceed to DB
        }

        // Async DB write: create transaction, update period, update execution
        DB::transaction(function () use ($execution, $cost, $period) {
            // Create credit transaction
            CreditTransaction::create([
                'workspace_id' => $execution->workspace_id,
                'usage_period_id' => $period->id,
                'type' => CreditTransactionType::Execution,
                'credits' => $cost,
                'description' => "Execution {$execution->id}",
                'execution_id' => $execution->id,
                'created_at' => now(),
            ]);

            // Update usage period
            $period->increment('credits_used', $cost);

            // Update execution
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

        // Get current period
        $period = $execution->workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return;
        }

        // Atomically refund from Redis
        try {
            Redis::incrby("credits:available:{$execution->workspace_id}", $credits);
        } catch (\Exception) {
            // Redis not available, proceed to DB
        }

        // DB transaction
        DB::transaction(function () use ($execution, $credits, $period) {
            // Create refund transaction
            CreditTransaction::create([
                'workspace_id' => $execution->workspace_id,
                'usage_period_id' => $period->id,
                'type' => CreditTransactionType::Refund,
                'credits' => -$credits,
                'description' => "Refund for execution {$execution->id}",
                'execution_id' => $execution->id,
                'created_at' => now(),
            ]);

            // Update usage period
            $period->decrement('credits_used', $credits);

            // Update execution
            $execution->update(['credits_consumed' => 0]);
        });
    }

    /**
     * Add credits from a purchased pack.
     */
    public function addPackCredits(CreditPack $pack): void
    {
        $workspace = $pack->workspace;

        // Get current period
        $period = $workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $period) {
            return;
        }

        // Update period with pack credits
        $period->increment('credits_from_packs', $pack->credits_amount);

        // Update Redis
        try {
            Redis::incrby("credits:available:{$workspace->id}", $pack->credits_amount);
        } catch (\Exception) {
            // Redis not available
        }

        // Create transaction record
        CreditTransaction::create([
            'workspace_id' => $workspace->id,
            'usage_period_id' => $period->id,
            'type' => CreditTransactionType::PackPurchase,
            'credits' => $pack->credits_amount,
            'description' => "Pack purchase {$pack->id}",
            'created_at' => now(),
        ]);
    }

    /**
     * Roll over to next period with logic for carrying over unused credits.
     * Closes current period, carries up to 50% if yearly+annual_rollover flag, creates new period.
     */
    public function rolloverPeriod(Workspace $workspace): void
    {
        // Get current period
        $currentPeriod = $workspace->usagePeriods()
            ->where('is_current', true)
            ->first();

        if (! $currentPeriod) {
            return;
        }

        DB::transaction(function () use ($workspace, $currentPeriod) {
            // Mark current period as not current
            $currentPeriod->update(['is_current' => false]);

            // Calculate rollover amount
            $subscription = $currentPeriod->subscription;
            $remaining = $currentPeriod->creditsRemaining();
            $rolledOver = 0;

            // Check for annual rollover eligibility
            if ($subscription && $subscription->billing_interval->value === 'yearly') {
                // Check if annual_rollover flag is enabled (assuming it's in workspace settings)
                $rolloverEnabled = $workspace->settings['annual_rollover'] ?? false;
                if ($rolloverEnabled && $remaining > 0) {
                    // Carry over up to 50% of remaining
                    $rolledOver = (int) floor($remaining * 0.5);
                }
            }

            // Create new period
            $periodStart = $currentPeriod->period_end->addDay();
            $periodEnd = match ($subscription->billing_interval->value) {
                'monthly' => $periodStart->addMonths(1)->subDay(),
                'yearly' => $periodStart->addYears(1)->subDay(),
                default => $periodStart->addMonths(1)->subDay(),
            };

            $newPeriod = $workspace->usagePeriods()->create([
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'credits_limit' => $subscription->credits_monthly,
                'credits_rolled_over' => $rolledOver,
                'is_current' => true,
            ]);

            // If rollover occurred, record it
            if ($rolledOver > 0) {
                CreditTransaction::create([
                    'workspace_id' => $workspace->id,
                    'usage_period_id' => $newPeriod->id,
                    'type' => CreditTransactionType::Rollover,
                    'credits' => $rolledOver,
                    'description' => "Rollover from period {$currentPeriod->id}",
                    'created_at' => now(),
                ]);
            }

            // Reset Redis key
            try {
                $totalAvailable = $newPeriod->credits_limit + $newPeriod->credits_rolled_over;
                Redis::set("credits:available:{$workspace->id}", $totalAvailable);
            } catch (\Exception) {
                // Redis not available
            }
        });
    }

    /**
     * Get credit cost for a specific node type.
     */
    private function getCreditCostForNodeType(string $nodeType): int
    {
        return self::CREDIT_COSTS[$nodeType] ?? self::CREDIT_COSTS['default_regular'];
    }
}

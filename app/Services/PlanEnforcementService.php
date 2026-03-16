<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Exceptions\Plan\FeatureNotAvailableException;
use App\Exceptions\Plan\InsufficientCreditsException;
use App\Exceptions\Plan\PlanLimitException;
use App\Exceptions\Plan\QuotaExceededException;
use App\Models\Workspace;
use Illuminate\Support\Facades\Redis;

class PlanEnforcementService
{
    /**
     * Check if the workspace has sufficient credits for the operation.
     *
     * @throws InsufficientCreditsException
     */
    public function checkCredits(Workspace $workspace, int $needed): void
    {
        $available = $this->getAvailableCredits($workspace);

        if ($available < $needed) {
            throw new InsufficientCreditsException(
                "Insufficient credits. Need {$needed}, have {$available}."
            );
        }
    }

    /**
     * Check if the workspace has a required feature.
     *
     * @throws FeatureNotAvailableException
     */
    public function requireFeature(Workspace $workspace, string $feature): void
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription || ! $subscription->plan->hasFeature($feature)) {
            throw new FeatureNotAvailableException(
                "Feature '{$feature}' is not available on this plan."
            );
        }
    }

    /**
     * Check if the workspace has exceeded its active workflows quota.
     *
     * @throws QuotaExceededException
     */
    public function checkActiveWorkflows(Workspace $workspace): void
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            throw new QuotaExceededException('No active subscription found.');
        }

        $limit = $subscription->plan->getLimit('active_workflows');

        // -1 or null = unlimited
        if ($limit === -1 || $limit === null) {
            return;
        }

        $activeCount = $workspace->workflows()
            ->where('is_active', true)
            ->count();

        if ($activeCount >= $limit) {
            throw new QuotaExceededException(
                "Active workflows limit ({$limit}) exceeded. Current: {$activeCount}."
            );
        }
    }

    /**
     * Check if the workspace has exceeded its members quota.
     *
     * @throws QuotaExceededException
     */
    public function checkMembers(Workspace $workspace): void
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            throw new QuotaExceededException('No active subscription found.');
        }

        $limit = $subscription->plan->getLimit('members');

        // -1 or null = unlimited
        if ($limit === -1 || $limit === null) {
            return;
        }

        $memberCount = $workspace->members()->count();

        if ($memberCount >= $limit) {
            throw new QuotaExceededException(
                "Members limit ({$limit}) exceeded. Current: {$memberCount}."
            );
        }
    }

    /**
     * Check if the minimum schedule interval is allowed.
     *
     * @throws PlanLimitException
     */
    public function checkScheduleInterval(Workspace $workspace, int $mins): void
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            throw new PlanLimitException('No active subscription found.');
        }

        $minInterval = $subscription->plan->getLimit('min_schedule_interval_minutes');

        // -1 or null = unlimited (no minimum)
        if ($minInterval === -1 || $minInterval === null) {
            return;
        }

        if ($mins < $minInterval) {
            throw new PlanLimitException(
                "Schedule interval ({$mins} mins) below minimum ({$minInterval} mins)."
            );
        }
    }

    /**
     * Get the maximum execution time in seconds for the workspace.
     */
    public function getMaxExecutionTime(Workspace $workspace): int
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            return 30; // sensible default
        }

        $limit = $subscription->plan->getLimit('max_execution_time_seconds');

        // -1 or null = unlimited, return a very high number
        if ($limit === -1 || $limit === null) {
            return PHP_INT_MAX;
        }

        return (int) $limit;
    }

    /**
     * Get the log retention days for the workspace.
     */
    public function getLogRetentionDays(Workspace $workspace): int
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            return 3; // sensible default
        }

        $limit = $subscription->plan->getLimit('execution_log_retention_days');

        // -1 or null = unlimited, return a very high number
        if ($limit === -1 || $limit === null) {
            return 36500; // ~100 years
        }

        return (int) $limit;
    }

    /**
     * Get the execution priority for the workspace.
     * Returns 'normal' or 'high'.
     */
    public function getExecutionPriority(Workspace $workspace): string
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            return 'normal';
        }

        $hasPriority = $subscription->plan->hasFeature('priority_execution');

        return $hasPriority ? 'high' : 'normal';
    }

    /**
     * Get the API rate limit per minute for the workspace.
     */
    public function getRateLimitPerMinute(Workspace $workspace): int
    {
        $workspace->load('subscriptions.plan');
        $subscription = $workspace->subscriptions()->first();

        if (! $subscription) {
            return 30; // sensible default
        }

        $limit = $subscription->plan->getLimit('api_rate_limit_per_minute');

        // -1 or null = unlimited
        if ($limit === -1 || $limit === null) {
            return PHP_INT_MAX;
        }

        return (int) $limit;
    }

    /**
     * Get available credits for the workspace.
     * Uses Redis fast path with database fallback.
     */
    private function getAvailableCredits(Workspace $workspace): int
    {
        try {
            // Try Redis fast path
            $credits = Redis::get("credits:available:{$workspace->id}");
            if ($credits !== null) {
                return (int) $credits;
            }
        } catch (\Exception) {
            // Redis not available, fall through to database
        }

        // Database fallback
        $subscription = $workspace->subscriptions()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
            ->first();

        if (! $subscription) {
            return 0;
        }

        // Get current usage from executions
        $used = $workspace->executions()
            ->whereNotNull('credits_consumed')
            ->sum('credits_consumed');

        $available = $subscription->credits_monthly - $used;

        return max(0, $available);
    }
}

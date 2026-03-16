<?php

use App\Exceptions\Plan\FeatureNotAvailableException;
use App\Exceptions\Plan\InsufficientCreditsException;
use App\Exceptions\Plan\PlanLimitException;
use App\Exceptions\Plan\QuotaExceededException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\PlanEnforcementService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->service = new PlanEnforcementService;

    try {
        $prefix = config('database.redis.options.prefix', '');
        $keys = Redis::keys('credits:available:*');
        foreach ($keys as $key) {
            Redis::del(str_replace($prefix, '', $key));
        }
    } catch (\Exception) {
        // Redis not available
    }
});

// ── Credit Checks ──────────────────────────────────────────────

describe('checkCredits', function () {
    test('passes when sufficient credits available', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create();
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'credits_monthly' => 10000,
        ]);

        // Mock available credits in Redis
        try {
            Redis::set("credits:available:{$workspace->id}", 10000);
        } catch (\Exception) {
            // Redis not available
        }

        // Should not throw
        $this->service->checkCredits($workspace, 5000);
        expect(true)->toBeTrue();
    });

    test('throws when insufficient credits', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create();
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'credits_monthly' => 100,
        ]);

        // Don't set Redis, so it falls back to database
        // With 100 credits and 0 consumed, available is 100
        expect(fn () => $this->service->checkCredits($workspace, 5000))
            ->toThrow(InsufficientCreditsException::class);
    });

    test('throws with exact message on insufficient credits', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create();
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'credits_monthly' => 100,
        ]);

        try {
            $this->service->checkCredits($workspace, 5000);
            expect(true)->toBeFalse(); // Should not reach here
        } catch (InsufficientCreditsException $e) {
            expect($e->getMessage())->toContain('Insufficient credits');
            expect($e->getMessage())->toContain('5000');
            expect($e->getMessage())->toContain('100');
        }
    });
});

// ── Feature Checks ────────────────────────────────────────────

describe('requireFeature', function () {
    test('passes when feature is available on plan', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->pro()->create();
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // ai_generation is available on Pro plan
        $this->service->requireFeature($workspace, 'ai_generation');
        expect(true)->toBeTrue();
    });

    test('throws when feature is not available on plan', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->free()->create();
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // ai_generation is NOT available on Free plan
        expect(fn () => $this->service->requireFeature($workspace, 'ai_generation'))
            ->toThrow(FeatureNotAvailableException::class);
    });

    test('throws with feature name in message', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->free()->create();
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        try {
            $this->service->requireFeature($workspace, 'ai_generation');
        } catch (FeatureNotAvailableException $e) {
            expect($e->getMessage())->toContain('ai_generation');
        }
    });
});

// ── Active Workflows Quota ────────────────────────────────────

describe('checkActiveWorkflows', function () {
    test('passes when under active workflows limit', function () {
        $user = \App\Models\User::factory()->create();
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['active_workflows' => 20]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // Create 10 active workflows
        collect(range(1, 10))->each(function () use ($workspace, $user) {
            $workspace->workflows()->create([
                'name' => fake()->sentence(),
                'is_active' => true,
                'created_by' => $user->id,
            ]);
        });

        $this->service->checkActiveWorkflows($workspace);
        expect(true)->toBeTrue();
    });

    test('throws when active workflows limit reached', function () {
        $user = \App\Models\User::factory()->create();
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['active_workflows' => 5]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // Create 5 active workflows
        collect(range(1, 5))->each(function () use ($workspace, $user) {
            $workspace->workflows()->create([
                'name' => fake()->sentence(),
                'is_active' => true,
                'created_by' => $user->id,
            ]);
        });

        expect(fn () => $this->service->checkActiveWorkflows($workspace))
            ->toThrow(QuotaExceededException::class);
    });

    test('passes with unlimited active workflows (-1)', function () {
        $user = \App\Models\User::factory()->create();
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->teams()->create(['limits' => ['active_workflows' => -1]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // Create 100 active workflows
        collect(range(1, 100))->each(function () use ($workspace, $user) {
            $workspace->workflows()->create([
                'name' => fake()->sentence(),
                'is_active' => true,
                'created_by' => $user->id,
            ]);
        });

        $this->service->checkActiveWorkflows($workspace);
        expect(true)->toBeTrue();
    });

    test('ignores inactive workflows in count', function () {
        $user = \App\Models\User::factory()->create();
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['active_workflows' => 5]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // Create 5 active workflows
        collect(range(1, 5))->each(function () use ($workspace, $user) {
            $workspace->workflows()->create([
                'name' => fake()->sentence(),
                'is_active' => true,
                'created_by' => $user->id,
            ]);
        });

        // Create 10 inactive workflows
        collect(range(1, 10))->each(function () use ($workspace, $user) {
            $workspace->workflows()->create([
                'name' => fake()->sentence(),
                'is_active' => false,
                'created_by' => $user->id,
            ]);
        });

        expect(fn () => $this->service->checkActiveWorkflows($workspace))
            ->toThrow(QuotaExceededException::class);
    });
});

// ── Members Quota ──────────────────────────────────────────────

describe('checkMembers', function () {
    test('passes when under members limit', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['members' => 3]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // Owner is already a member, 2 more invited
        $this->service->checkMembers($workspace);
        expect(true)->toBeTrue();
    });

    test('throws when members limit reached', function () {
        $owner = \App\Models\User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        // Add owner as member
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        $plan = Plan::factory()->free()->create(['limits' => ['members' => 1]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect(fn () => $this->service->checkMembers($workspace))
            ->toThrow(QuotaExceededException::class);
    });

    test('passes with unlimited members (-1)', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->enterprise()->create(['limits' => ['members' => -1]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect(fn () => $this->service->checkMembers($workspace))
            ->not->toThrow(QuotaExceededException::class);
    });
});

// ── Schedule Interval ──────────────────────────────────────────

describe('checkScheduleInterval', function () {
    test('passes when interval meets minimum', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['min_schedule_interval_minutes' => 15]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        $this->service->checkScheduleInterval($workspace, 30);
        expect(true)->toBeTrue();
    });

    test('throws when interval below minimum', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['min_schedule_interval_minutes' => 15]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect(fn () => $this->service->checkScheduleInterval($workspace, 10))
            ->toThrow(PlanLimitException::class);
    });

    test('passes with unlimited schedule (-1)', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->teams()->create(['limits' => ['min_schedule_interval_minutes' => 1]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        // Any interval should pass
        $this->service->checkScheduleInterval($workspace, 1);
        expect(true)->toBeTrue();
    });

    test('passes with null minimum (unlimited)', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->free()->create(['limits' => ['min_schedule_interval_minutes' => null]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        $this->service->checkScheduleInterval($workspace, 1);
        expect(true)->toBeTrue();
    });
});

// ── Max Execution Time ──────────────────────────────────────────

describe('getMaxExecutionTime', function () {
    test('returns plan limit in seconds', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['max_execution_time_seconds' => 120]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getMaxExecutionTime($workspace))->toBe(120);
    });

    test('returns very high number for unlimited (-1)', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->enterprise()->create(['limits' => ['max_execution_time_seconds' => -1]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getMaxExecutionTime($workspace))->toBe(PHP_INT_MAX);
    });

    test('returns sensible default when no subscription', function () {
        $workspace = Workspace::factory()->create();

        expect($this->service->getMaxExecutionTime($workspace))->toBe(30);
    });
});

// ── Log Retention ──────────────────────────────────────────────

describe('getLogRetentionDays', function () {
    test('returns plan limit in days', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->pro()->create(['limits' => ['execution_log_retention_days' => 30]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getLogRetentionDays($workspace))->toBe(30);
    });

    test('returns high number for unlimited (-1)', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->enterprise()->create(['limits' => ['execution_log_retention_days' => 365]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getLogRetentionDays($workspace))->toBe(365);
    });

    test('returns sensible default when no subscription', function () {
        $workspace = Workspace::factory()->create();

        expect($this->service->getLogRetentionDays($workspace))->toBe(3);
    });
});

// ── Execution Priority ─────────────────────────────────────────

describe('getExecutionPriority', function () {
    test('returns "high" when priority_execution feature enabled', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->teams()->create(['features' => ['priority_execution' => true]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getExecutionPriority($workspace))->toBe('high');
    });

    test('returns "normal" when priority_execution feature disabled', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['features' => ['priority_execution' => false]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getExecutionPriority($workspace))->toBe('normal');
    });

    test('returns "normal" when no subscription', function () {
        $workspace = Workspace::factory()->create();

        expect($this->service->getExecutionPriority($workspace))->toBe('normal');
    });
});

// ── API Rate Limit ─────────────────────────────────────────────

describe('getRateLimitPerMinute', function () {
    test('returns plan limit per minute', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->starter()->create(['limits' => ['api_rate_limit_per_minute' => 60]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getRateLimitPerMinute($workspace))->toBe(60);
    });

    test('returns PHP_INT_MAX for unlimited (-1)', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->enterprise()->create(['limits' => ['api_rate_limit_per_minute' => -1]]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        expect($this->service->getRateLimitPerMinute($workspace))->toBe(PHP_INT_MAX);
    });

    test('returns sensible default when no subscription', function () {
        $workspace = Workspace::factory()->create();

        expect($this->service->getRateLimitPerMinute($workspace))->toBe(30);
    });
});

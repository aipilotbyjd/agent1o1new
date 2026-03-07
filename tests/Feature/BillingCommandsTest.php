<?php

use App\Enums\CreditPackStatus;
use App\Models\CreditPack;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageDailySnapshot;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;

describe('billing:expire-credit-packs', function () {
    it('marks expired active packs as expired', function () {
        $workspace = Workspace::factory()->create();

        $expiredPack = CreditPack::factory()->for($workspace)->create([
            'status' => CreditPackStatus::Active,
            'expires_at' => now()->subDay(),
        ]);

        $activePack = CreditPack::factory()->for($workspace)->create([
            'status' => CreditPackStatus::Active,
            'expires_at' => now()->addMonth(),
        ]);

        $this->artisan('billing:expire-credit-packs')
            ->expectsOutputToContain('Expired 1 credit pack(s)')
            ->assertSuccessful();

        expect($expiredPack->refresh()->status)->toBe(CreditPackStatus::Expired);
        expect($activePack->refresh()->status)->toBe(CreditPackStatus::Active);
    });

    it('does not touch non-active packs', function () {
        $workspace = Workspace::factory()->create();

        CreditPack::factory()->for($workspace)->create([
            'status' => CreditPackStatus::Exhausted,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('billing:expire-credit-packs')
            ->expectsOutputToContain('Expired 0 credit pack(s)')
            ->assertSuccessful();
    });
});

describe('billing:reset-monthly-credits', function () {
    it('rolls over periods that have reached their end date', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->create([
            'limits' => ['credits_monthly' => 1000],
            'features' => ['annual_rollover' => false],
        ]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'credits_monthly' => 1000,
        ]);

        $period = WorkspaceUsagePeriod::factory()->create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $subscription->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => today()->toDateString(),
            'credits_limit' => 1000,
            'credits_used' => 200,
            'is_current' => true,
        ]);

        $this->artisan('billing:reset-monthly-credits')
            ->expectsOutputToContain('Rolled over 1 usage period(s)')
            ->assertSuccessful();

        expect($period->refresh()->is_current)->toBeFalse();

        $newPeriod = $workspace->usagePeriods()->where('is_current', true)->first();
        expect($newPeriod)->not->toBeNull();
        expect($newPeriod->id)->not->toBe($period->id);
    });

    it('skips periods that have not reached their end date', function () {
        $workspace = Workspace::factory()->create();
        $plan = Plan::factory()->create([
            'limits' => ['credits_monthly' => 1000],
            'features' => [],
        ]);
        $subscription = Subscription::factory()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'credits_monthly' => 1000,
        ]);

        WorkspaceUsagePeriod::factory()->create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $subscription->id,
            'period_end' => now()->addDays(10)->toDateString(),
            'is_current' => true,
        ]);

        $this->artisan('billing:reset-monthly-credits')
            ->expectsOutputToContain('Rolled over 0 usage period(s)')
            ->assertSuccessful();
    });
});

describe('billing:snapshot-daily-usage', function () {
    it('creates daily snapshots for workspaces with current periods', function () {
        $workspace = Workspace::factory()->create();

        WorkspaceUsagePeriod::factory()->create([
            'workspace_id' => $workspace->id,
            'credits_used' => 150,
            'executions_total' => 30,
            'executions_succeeded' => 25,
            'executions_failed' => 5,
            'nodes_executed' => 200,
            'ai_nodes_executed' => 10,
            'is_current' => true,
        ]);

        $this->artisan('billing:snapshot-daily-usage')
            ->expectsOutputToContain('Created 1 daily usage snapshot(s)')
            ->assertSuccessful();

        $snapshot = UsageDailySnapshot::where('workspace_id', $workspace->id)->first();
        expect($snapshot)->not->toBeNull();
        expect($snapshot->snapshot_date->toDateString())->toBe(today()->subDay()->toDateString());
        expect($snapshot->credits_used)->toBe(150);
        expect($snapshot->executions_total)->toBe(30);
    });

    it('does not create duplicate snapshots', function () {
        $workspace = Workspace::factory()->create();

        WorkspaceUsagePeriod::factory()->create([
            'workspace_id' => $workspace->id,
            'credits_used' => 100,
            'is_current' => true,
        ]);

        UsageDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'snapshot_date' => today()->subDay()->toDateString(),
        ]);

        $this->artisan('billing:snapshot-daily-usage')
            ->expectsOutputToContain('Created 0 daily usage snapshot(s)')
            ->assertSuccessful();

        expect(UsageDailySnapshot::where('workspace_id', $workspace->id)->count())->toBe(1);
    });
});

<?php

use App\Enums\BillingInterval;
use App\Enums\CreditPackStatus;
use App\Enums\CreditTransactionType;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use App\Services\CreditMeterService;
use Illuminate\Support\Facades\Redis;

describe('CreditMeterService', function () {
    beforeEach(function () {
        try {
            $prefix = config('database.redis.options.prefix', '');
            $keys = Redis::keys('credits:available:*');
            foreach ($keys as $key) {
                Redis::del(str_replace($prefix, '', $key));
            }
        } catch (\Exception) {
            // Redis not available
        }

        $this->service = new CreditMeterService;
        $this->workspace = Workspace::factory()->create();

        // Bootstrap billing state
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'limits' => ['credits_monthly' => 100],
            'features' => ['annual_rollover' => false, 'credit_packs' => true],
        ]);

        $subscription = Subscription::factory()->create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_interval' => BillingInterval::Monthly,
            'credits_monthly' => 100,
        ]);

        WorkspaceUsagePeriod::factory()->create([
            'workspace_id' => $this->workspace->id,
            'subscription_id' => $subscription->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'credits_limit' => 100,
            'credits_used' => 0,
            'is_current' => true,
        ]);
    });

    // ── getAvailable() ──────────────────────────────────────────────

    describe('getAvailable', function () {
        it('returns available credits from database', function () {
            $available = $this->service->getAvailable($this->workspace);
            expect($available)->toBe(100);
        });

        it('includes credits from usable packs', function () {
            CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 50,
                'credits_remaining' => 50,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->addMonths(6),
            ]);

            $available = $this->service->getAvailable($this->workspace);
            expect($available)->toBe(150);
        });

        it('excludes expired packs', function () {
            CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 50,
                'credits_remaining' => 50,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->subDay(),
            ]);

            $available = $this->service->getAvailable($this->workspace);
            expect($available)->toBe(100);
        });

        it('excludes exhausted packs', function () {
            CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 50,
                'credits_remaining' => 0,
                'status' => CreditPackStatus::Exhausted,
            ]);

            $available = $this->service->getAvailable($this->workspace);
            expect($available)->toBe(100);
        });

        it('returns zero when no period exists', function () {
            $newWorkspace = Workspace::factory()->create();
            expect($this->service->getAvailable($newWorkspace))->toBe(0);
        });
    });

    // ── calculateCost() ─────────────────────────────────────────────

    describe('calculateCost', function () {
        it('calculates zero cost for trigger nodes', function () {
            $nodes = [ExecutionNode::factory()->create(['node_type' => 'trigger_webhook'])];
            expect($this->service->calculateCost($nodes))->toBe(0);
        });

        it('calculates 1 credit for regular nodes', function () {
            $nodes = [
                ExecutionNode::factory()->create(['node_type' => 'action_http_request']),
                ExecutionNode::factory()->create(['node_type' => 'logic_if']),
            ];
            expect($this->service->calculateCost($nodes))->toBe(2);
        });

        it('calculates 2 credits for code nodes', function () {
            $nodes = [ExecutionNode::factory()->create(['node_type' => 'action_transform'])];
            expect($this->service->calculateCost($nodes))->toBe(2);
        });

        it('calculates 10 credits for AI nodes', function () {
            $nodes = [ExecutionNode::factory()->create(['node_type' => 'ai_generate'])];
            expect($this->service->calculateCost($nodes))->toBe(10);
        });

        it('calculates mixed node costs correctly', function () {
            $nodes = [
                ExecutionNode::factory()->create(['node_type' => 'trigger_webhook']),   // 0
                ExecutionNode::factory()->create(['node_type' => 'action_http_request']), // 1
                ExecutionNode::factory()->create(['node_type' => 'action_transform']),   // 2
                ExecutionNode::factory()->create(['node_type' => 'ai_generate']),        // 10
                ExecutionNode::factory()->create(['node_type' => 'logic_if']),           // 1
            ];
            expect($this->service->calculateCost($nodes))->toBe(14);
        });
    });

    // ── consume() ───────────────────────────────────────────────────

    describe('consume', function () {
        it('returns zero for failed executions', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->failed()
                ->create();

            $nodes = [ExecutionNode::factory()->for($execution)->create(['node_type' => 'action_transform'])];

            expect($this->service->consume($execution, $nodes))->toBe(0);
        });

        it('returns zero for cancelled executions', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->cancelled()
                ->create();

            $nodes = [ExecutionNode::factory()->for($execution)->create(['node_type' => 'action_http_request'])];

            expect($this->service->consume($execution, $nodes))->toBe(0);
        });

        it('consumes credits and records transaction', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create();

            $nodes = [ExecutionNode::factory()->for($execution)->create(['node_type' => 'action_http_request'])];

            $consumed = $this->service->consume($execution, $nodes);

            expect($consumed)->toBe(1);
            expect($execution->refresh()->credits_consumed)->toBe(1);

            $transaction = CreditTransaction::where('execution_id', $execution->id)->first();
            expect($transaction)->not->toBeNull();
            expect($transaction->credits)->toBe(1);
            expect($transaction->type)->toBe(CreditTransactionType::Execution);
        });

        it('updates usage period credits_used', function () {
            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();

            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create();

            $nodes = [ExecutionNode::factory()->for($execution)->create(['node_type' => 'action_transform'])];

            $this->service->consume($execution, $nodes);

            expect($period->refresh()->credits_used)->toBe(2);
        });

        it('updates usage period execution statistics', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create();

            $nodes = [
                ExecutionNode::factory()->for($execution)->create(['node_type' => 'action_http_request']),
                ExecutionNode::factory()->for($execution)->create(['node_type' => 'ai_generate']),
            ];

            $this->service->consume($execution, $nodes);

            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
            expect($period->executions_total)->toBe(1);
            expect($period->executions_succeeded)->toBe(1);
            expect($period->nodes_executed)->toBe(2);
            expect($period->ai_nodes_executed)->toBe(1);
        });

        it('is idempotent — does not double charge', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create();

            $nodes = [ExecutionNode::factory()->for($execution)->create(['node_type' => 'action_http_request'])];

            $consumed1 = $this->service->consume($execution, $nodes);
            $consumed2 = $this->service->consume($execution, $nodes);

            expect($consumed1)->toBe(1);
            expect($consumed2)->toBe(1);

            $count = CreditTransaction::where('execution_id', $execution->id)
                ->where('type', CreditTransactionType::Execution)
                ->count();
            expect($count)->toBe(1);
        });
    });

    // ── refund() ────────────────────────────────────────────────────

    describe('refund', function () {
        it('refunds credits and creates refund transaction', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create(['credits_consumed' => 5]);

            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
            $period->update(['credits_used' => 10]);

            $this->service->refund($execution);

            $refund = CreditTransaction::where('execution_id', $execution->id)
                ->where('type', CreditTransactionType::Refund)
                ->first();
            expect($refund)->not->toBeNull();
            expect($refund->credits)->toBe(-5);

            expect($period->refresh()->credits_used)->toBe(5);
            expect($execution->refresh()->credits_consumed)->toBe(0);
        });

        it('does nothing when credits_consumed is zero (explicit)', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create(['credits_consumed' => 0]);

            $this->service->refund($execution);

            expect(CreditTransaction::where('execution_id', $execution->id)->count())->toBe(0);
        });

        it('does nothing when credits_consumed defaults to zero', function () {
            $execution = Execution::factory()
                ->for($this->workspace)
                ->completed()
                ->create(['credits_consumed' => 0]);

            $this->service->refund($execution);

            expect(CreditTransaction::where('execution_id', $execution->id)->count())->toBe(0);
        });
    });

    // ── addPackCredits() ────────────────────────────────────────────

    describe('addPackCredits', function () {
        it('adds pack credits to usage period', function () {
            $pack = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 200,
                'credits_remaining' => 200,
            ]);

            $this->service->addPackCredits($pack);

            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
            expect($period->credits_from_packs)->toBe(200);
        });

        it('records pack purchase transaction', function () {
            $pack = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 50,
                'credits_remaining' => 50,
            ]);

            $this->service->addPackCredits($pack);

            $tx = CreditTransaction::where('type', CreditTransactionType::PackPurchase)->first();
            expect($tx)->not->toBeNull();
            expect($tx->credits)->toBe(50);
        });
    });

    // ── rolloverPeriod() ────────────────────────────────────────────

    describe('rolloverPeriod', function () {
        it('closes current period and creates a new one', function () {
            $oldPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();

            $this->service->rolloverPeriod($this->workspace);

            expect($oldPeriod->refresh()->is_current)->toBeFalse();

            $newPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
            expect($newPeriod)->not->toBeNull();
            expect($newPeriod->id)->not->toBe($oldPeriod->id);
        });

        it('does not rollover credits for monthly plans', function () {
            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
            $period->update(['credits_used' => 50, 'period_end' => now()->toDateString()]);

            $this->service->rolloverPeriod($this->workspace);

            $newPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
            expect($newPeriod->credits_rolled_over)->toBe(0);
        });

        it('carries 50% of unused credits for yearly plans with annual_rollover', function () {
            // Update plan to enable rollover
            $subscription = $this->workspace->subscriptions()->first();
            $plan = $subscription->plan;
            $plan->update(['features' => array_merge($plan->features, ['annual_rollover' => true])]);
            $subscription->update([
                'billing_interval' => BillingInterval::Yearly,
                'credits_monthly' => 1000,
            ]);

            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
            $period->update([
                'credits_limit' => 1000,
                'credits_used' => 600,
                'period_end' => now()->toDateString(),
            ]);

            $this->service->rolloverPeriod($this->workspace);

            $newPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
            expect($newPeriod->credits_rolled_over)->toBe(200); // 50% of 400
        });

        it('records rollover transaction', function () {
            $subscription = $this->workspace->subscriptions()->first();
            $plan = $subscription->plan;
            $plan->update(['features' => array_merge($plan->features, ['annual_rollover' => true])]);
            $subscription->update([
                'billing_interval' => BillingInterval::Yearly,
                'credits_monthly' => 1000,
            ]);

            $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
            $period->update([
                'credits_limit' => 1000,
                'credits_used' => 500,
                'period_end' => now()->toDateString(),
            ]);

            $this->service->rolloverPeriod($this->workspace);

            $tx = CreditTransaction::where('type', CreditTransactionType::Rollover)->first();
            expect($tx)->not->toBeNull();
            expect($tx->credits)->toBe(250); // 50% of 500
        });
    });

    // ── drainPacks() ────────────────────────────────────────────────

    describe('drainPacks', function () {
        it('drains packs in FIFO order by expiry date', function () {
            $pack1 = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 100,
                'credits_remaining' => 30,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->addMonths(3),
            ]);

            $pack2 = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 100,
                'credits_remaining' => 50,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->addMonths(6),
            ]);

            $drained = $this->service->drainPacks($this->workspace, 40);

            expect($drained)->toBe(40);
            expect($pack1->refresh()->credits_remaining)->toBe(0);
            expect($pack1->status)->toBe(CreditPackStatus::Exhausted);
            expect($pack2->refresh()->credits_remaining)->toBe(40);
        });

        it('skips expired packs', function () {
            CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 100,
                'credits_remaining' => 100,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->subDay(),
            ]);

            $drained = $this->service->drainPacks($this->workspace, 50);
            expect($drained)->toBe(0);
        });
    });

    // ── CreditPack model fixes ──────────────────────────────────────

    describe('CreditPack model', function () {
        it('isUsable returns false for expired packs', function () {
            $pack = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 100,
                'credits_remaining' => 100,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->subDay(),
            ]);

            expect($pack->isUsable())->toBeFalse();
        });

        it('isUsable returns true for active non-expired packs', function () {
            $pack = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 100,
                'credits_remaining' => 100,
                'status' => CreditPackStatus::Active,
                'expires_at' => now()->addMonths(6),
            ]);

            expect($pack->isUsable())->toBeTrue();
        });

        it('consume does not go negative', function () {
            $pack = CreditPack::factory()->for($this->workspace)->create([
                'credits_amount' => 10,
                'credits_remaining' => 5,
                'status' => CreditPackStatus::Active,
            ]);

            $pack->consume(20);

            expect($pack->refresh()->credits_remaining)->toBe(0);
            expect($pack->status)->toBe(CreditPackStatus::Exhausted);
        });
    });
});

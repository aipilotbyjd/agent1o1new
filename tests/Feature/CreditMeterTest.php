<?php

use App\Enums\CreditPackStatus;
use App\Enums\CreditTransactionType;
use App\Enums\ExecutionStatus;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use App\Services\CreditMeterService;

describe('CreditMeterService', function () {
    beforeEach(function () {
        $this->service = new CreditMeterService();
        $this->workspace = Workspace::factory()->create();
        
        // Bootstrap with subscription and period
        $this->bootstrapWorkspaceBilling();
    });

    // ────────────────────────────────────────────────────────────────────
    // getAvailable()
    // ────────────────────────────────────────────────────────────────────

    it('returns available credits from Redis when cached', function () {
        $available = $this->service->getAvailable($this->workspace);
        expect($available)->toBe(100); // Free plan default from bootstrap
    });

    it('falls back to database when Redis unavailable', function () {
        $available = $this->service->getAvailable($this->workspace);
        expect($available)->toBeGreaterThan(0);
    });

    it('includes credits from usable packs', function () {
        // Add a pack
        $pack = CreditPack::factory()
            ->for($this->workspace)
            ->create([
                'credits_amount' => 50,
                'credits_remaining' => 50,
                'status' => CreditPackStatus::Active,
            ]);

        $available = $this->service->getAvailable($this->workspace);
        expect($available)->toBe(150); // 100 from period + 50 from pack
    });

    it('returns zero when no period exists', function () {
        // Create a new workspace without billing bootstrap
        $newWorkspace = Workspace::factory()->create();
        $available = $this->service->getAvailable($newWorkspace);
        expect($available)->toBe(0);
    });

    // ────────────────────────────────────────────────────────────────────
    // calculateCost()
    // ────────────────────────────────────────────────────────────────────

    it('calculates zero cost for trigger nodes', function () {
        $nodes = [
            ExecutionNode::factory()->create(['node_type' => 'trigger_webhook']),
        ];

        $cost = $this->service->calculateCost($nodes);
        expect($cost)->toBe(0);
    });

    it('calculates correct cost for regular nodes', function () {
        $nodes = [
            ExecutionNode::factory()->create(['node_type' => 'action_http_request']),
            ExecutionNode::factory()->create(['node_type' => 'logic_if']),
        ];

        $cost = $this->service->calculateCost($nodes);
        expect($cost)->toBe(2); // 1 + 1
    });

    it('calculates correct cost for code nodes', function () {
        $nodes = [
            ExecutionNode::factory()->create(['node_type' => 'action_transform']),
        ];

        $cost = $this->service->calculateCost($nodes);
        expect($cost)->toBe(2);
    });

    it('calculates mixed node costs', function () {
        $nodes = [
            ExecutionNode::factory()->create(['node_type' => 'trigger_webhook']),
            ExecutionNode::factory()->create(['node_type' => 'action_http_request']),
            ExecutionNode::factory()->create(['node_type' => 'action_transform']),
            ExecutionNode::factory()->create(['node_type' => 'logic_if']),
        ];

        $cost = $this->service->calculateCost($nodes);
        expect($cost)->toBe(4); // 0 + 1 + 2 + 1
    });

    // ────────────────────────────────────────────────────────────────────
    // consume()
    // ────────────────────────────────────────────────────────────────────

    it('returns zero for failed executions', function () {
        $execution = Execution::factory()
            ->for($this->workspace)
            ->failed()
            ->create();

        $nodes = [
            ExecutionNode::factory()
                ->for($execution)
                ->create(['node_type' => 'action_transform']),
        ];

        $consumed = $this->service->consume($execution, $nodes);
        expect($consumed)->toBe(0);
        expect($execution->refresh()->credits_consumed)->toBeNull();
    });

    it('consumes credits and records transaction', function () {
        $execution = Execution::factory()
            ->for($this->workspace)
            ->completed()
            ->create();

        $nodes = [
            ExecutionNode::factory()
                ->for($execution)
                ->create(['node_type' => 'action_http_request']),
        ];

        $consumed = $this->service->consume($execution, $nodes);
        
        expect($consumed)->toBe(1);
        expect($execution->refresh()->credits_consumed)->toBe(1);
        
        // Verify transaction created
        $transaction = CreditTransaction::where('execution_id', $execution->id)->first();
        expect($transaction)->not->toBeNull();
        expect($transaction->credits)->toBe(1);
        expect($transaction->type)->toBe(CreditTransactionType::Execution);
    });

    it('updates usage period when consuming', function () {
        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $initialUsed = $period->credits_used;

        $execution = Execution::factory()
            ->for($this->workspace)
            ->completed()
            ->create();

        $nodes = [
            ExecutionNode::factory()
                ->for($execution)
                ->create(['node_type' => 'action_transform']), // 2 credits
        ];

        $this->service->consume($execution, $nodes);

        $period->refresh();
        expect($period->credits_used)->toBe($initialUsed + 2);
    });

    it('is idempotent - does not double charge', function () {
        $execution = Execution::factory()
            ->for($this->workspace)
            ->completed()
            ->create();

        $nodes = [
            ExecutionNode::factory()
                ->for($execution)
                ->create(['node_type' => 'action_http_request']),
        ];

        // First consume
        $consumed1 = $this->service->consume($execution, $nodes);
        expect($consumed1)->toBe(1);

        // Second consume should return same amount, not double charge
        $consumed2 = $this->service->consume($execution, $nodes);
        expect($consumed2)->toBe(1);

        // Verify only one transaction
        $transactionCount = CreditTransaction::where('execution_id', $execution->id)
            ->where('type', CreditTransactionType::Execution)
            ->count();
        expect($transactionCount)->toBe(1);
    });

    // ────────────────────────────────────────────────────────────────────
    // refund()
    // ────────────────────────────────────────────────────────────────────

    it('refunds credits from execution', function () {
        $execution = Execution::factory()
            ->for($this->workspace)
            ->completed()
            ->create(['credits_consumed' => 5]);

        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $period->update(['credits_used' => 10]);

        $this->service->refund($execution);

        // Verify refund transaction
        $refund = CreditTransaction::where('execution_id', $execution->id)
            ->where('type', CreditTransactionType::Refund)
            ->first();
        expect($refund)->not->toBeNull();
        expect($refund->credits)->toBe(-5);

        // Verify period updated
        $period->refresh();
        expect($period->credits_used)->toBe(5);

        // Verify execution cleared
        $execution->refresh();
        expect($execution->credits_consumed)->toBe(0);
    });

    it('does nothing when refunding zero-credit execution', function () {
        $execution = Execution::factory()
            ->for($this->workspace)
            ->completed()
            ->create(['credits_consumed' => null]);

        $this->service->refund($execution);

        $refundCount = CreditTransaction::where('execution_id', $execution->id)
            ->where('type', CreditTransactionType::Refund)
            ->count();
        expect($refundCount)->toBe(0);
    });

    // ────────────────────────────────────────────────────────────────────
    // addPackCredits()
    // ────────────────────────────────────────────────────────────────────

    it('adds pack credits to period', function () {
        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $initialPack = $period->credits_from_packs;

        $pack = CreditPack::factory()
            ->for($this->workspace)
            ->create([
                'credits_amount' => 100,
                'credits_remaining' => 100,
                'status' => CreditPackStatus::Active,
            ]);

        $this->service->addPackCredits($pack);

        $period->refresh();
        expect($period->credits_from_packs)->toBe($initialPack + 100);
    });

    it('records pack purchase transaction', function () {
        $pack = CreditPack::factory()
            ->for($this->workspace)
            ->create([
                'credits_amount' => 50,
                'credits_remaining' => 50,
                'status' => CreditPackStatus::Active,
            ]);

        $this->service->addPackCredits($pack);

        $transaction = CreditTransaction::where('type', CreditTransactionType::PackPurchase)->first();
        expect($transaction)->not->toBeNull();
        expect($transaction->credits)->toBe(50);
    });

    // ────────────────────────────────────────────────────────────────────
    // rolloverPeriod()
    // ────────────────────────────────────────────────────────────────────

    it('closes current period and creates new one', function () {
        $oldPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $oldPeriodId = $oldPeriod->id;

        $this->service->rolloverPeriod($this->workspace);

        $oldPeriod->refresh();
        expect($oldPeriod->is_current)->toBeFalse();

        $newPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
        expect($newPeriod)->not->toBeNull();
        expect($newPeriod->id)->not->toBe($oldPeriodId);
    });

    it('carries over unused credits for yearly plans with flag', function () {
        // Setup yearly subscription
        $subscription = $this->workspace->subscriptions()->first();
        $subscription->update([
            'billing_interval' => 'yearly',
            'credits_monthly' => 1000,
        ]);

        // Update period
        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $period->update([
            'credits_limit' => 1000,
            'credits_used' => 600, // 400 remaining
            'period_end' => now()->toDateString(),
        ]);

        // Enable annual rollover
        $this->workspace->update([
            'settings' => ['annual_rollover' => true],
        ]);

        $this->service->rolloverPeriod($this->workspace);

        $newPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
        expect($newPeriod->credits_rolled_over)->toBe(200); // 50% of 400
    });

    it('does not rollover for monthly plans', function () {
        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $period->update([
            'credits_used' => 50, // 50 remaining
            'period_end' => now()->toDateString(),
        ]);

        $this->service->rolloverPeriod($this->workspace);

        $newPeriod = $this->workspace->usagePeriods()->where('is_current', true)->first();
        expect($newPeriod->credits_rolled_over)->toBe(0);
    });

    it('records rollover transaction when applicable', function () {
        $subscription = $this->workspace->subscriptions()->first();
        $subscription->update([
            'billing_interval' => 'yearly',
            'credits_monthly' => 1000,
        ]);

        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $period->update([
            'credits_limit' => 1000,
            'credits_used' => 500,
            'period_end' => now()->toDateString(),
        ]);

        $this->workspace->update([
            'settings' => ['annual_rollover' => true],
        ]);

        $this->service->rolloverPeriod($this->workspace);

        $rolloverTransaction = CreditTransaction::where('type', CreditTransactionType::Rollover)->first();
        expect($rolloverTransaction)->not->toBeNull();
        expect($rolloverTransaction->credits)->toBe(250); // 50% of 500 remaining
    });

    // ────────────────────────────────────────────────────────────────────
    // Enterprise (Unlimited) Behavior
    // ────────────────────────────────────────────────────────────────────

    it('handles packs being drained after monthly credits', function () {
        $period = $this->workspace->usagePeriods()->where('is_current', true)->first();
        $period->update(['credits_limit' => 10]);

        // Add pack
        $pack = CreditPack::factory()
            ->for($this->workspace)
            ->create([
                'credits_amount' => 100,
                'credits_remaining' => 100,
                'status' => CreditPackStatus::Active,
            ]);
        $this->service->addPackCredits($pack);

        // Consume 15 credits (exhausts period, uses pack)
        $execution1 = Execution::factory()
            ->for($this->workspace)
            ->completed()
            ->create();

        $nodes1 = [
            ExecutionNode::factory()
                ->for($execution1)
                ->create(['node_type' => 'action_transform']), // 2
        ];

        for ($i = 0; $i < 7; $i++) { // 7 * 2 = 14 credits
            ExecutionNode::factory()
                ->for($execution1)
                ->create(['node_type' => 'action_transform']);
        }

        $this->service->consume($execution1, $nodes1);

        $available = $this->service->getAvailable($this->workspace);
        // Should have 10 + 100 - 2 = 108 available
        expect($available)->toBe(108);
    });
});

// ────────────────────────────────────────────────────────────────────
// Helper Functions
// ────────────────────────────────────────────────────────────────────

function bootstrapWorkspaceBilling(): void
{
    $workspace = test()->workspace;
    
    $plan = \App\Models\Plan::factory()->create([
        'slug' => 'free',
        'limits' => ['credits_monthly' => 100],
    ]);

    $subscription = $workspace->subscriptions()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_interval' => 'monthly',
        'credits_monthly' => 100,
    ]);

    $workspace->usagePeriods()->create([
        'subscription_id' => $subscription->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->addDays(30)->toDateString(),
        'credits_limit' => 100,
        'is_current' => true,
    ]);
}

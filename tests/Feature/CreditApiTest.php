<?php

use App\Enums\BillingInterval;
use App\Enums\CreditTransactionType;
use App\Enums\Role;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupCreditWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    $plan = Plan::factory()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'limits' => ['credits_monthly' => 500],
    ]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_interval' => BillingInterval::Monthly,
        'credits_monthly' => 500,
    ]);

    $period = WorkspaceUsagePeriod::factory()->create([
        'workspace_id' => $workspace->id,
        'subscription_id' => $subscription->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'credits_limit' => 500,
        'credits_used' => 120,
        'credits_from_packs' => 50,
        'credits_rolled_over' => 10,
        'is_current' => true,
    ]);

    return [$owner, $workspace, $plan, $subscription, $period];
}

// ── Balance ─────────────────────────────────────────────────

test('owner can view credit balance', function () {
    [$owner, $workspace, $plan, $subscription, $period] = setupCreditWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/balance");

    $response->assertSuccessful()
        ->assertJsonPath('data.workspace_id', $workspace->id)
        ->assertJsonPath('data.plan.name', 'Pro')
        ->assertJsonPath('data.plan.slug', 'pro')
        ->assertJsonPath('data.billing_interval', 'monthly')
        ->assertJsonPath('data.credits.limit', 500)
        ->assertJsonPath('data.credits.used', 120)
        ->assertJsonPath('data.credits.remaining', 440)
        ->assertJsonPath('data.credits.from_packs', 50)
        ->assertJsonPath('data.credits.rolled_over', 10)
        ->assertJsonPath('data.period.start', now()->startOfMonth()->toDateString())
        ->assertJsonPath('data.period.end', now()->endOfMonth()->toDateString());
});

test('balance returns nulls when no subscription or period exists', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/balance");

    $response->assertSuccessful()
        ->assertJsonPath('data.workspace_id', $workspace->id)
        ->assertJsonPath('data.plan.name', null)
        ->assertJsonPath('data.credits.limit', null)
        ->assertJsonPath('data.period.start', null);
});

test('non-member cannot view credit balance', function () {
    [$owner, $workspace] = setupCreditWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/balance");

    $response->assertForbidden();
});

test('unauthenticated user cannot view credit balance', function () {
    [, $workspace] = setupCreditWorkspace();

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/credits/balance");

    $response->assertUnauthorized();
});

// ── Transactions ────────────────────────────────────────────

test('owner can list credit transactions', function () {
    [$owner, $workspace, , , $period] = setupCreditWorkspace();

    CreditTransaction::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
        'usage_period_id' => $period->id,
        'type' => CreditTransactionType::Execution,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/transactions");

    $response->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'type', 'credits', 'description', 'execution_id', 'created_at'],
            ],
        ]);
});

test('transactions are paginated', function () {
    [$owner, $workspace, , , $period] = setupCreditWorkspace();

    CreditTransaction::factory()->count(30)->create([
        'workspace_id' => $workspace->id,
        'usage_period_id' => $period->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/transactions");

    $response->assertSuccessful()
        ->assertJsonCount(25, 'data')
        ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'total']]);
});

test('transactions are ordered by created_at desc', function () {
    [$owner, $workspace, , , $period] = setupCreditWorkspace();

    CreditTransaction::factory()->create([
        'workspace_id' => $workspace->id,
        'usage_period_id' => $period->id,
        'description' => 'older',
        'created_at' => now()->subHour(),
    ]);
    CreditTransaction::factory()->create([
        'workspace_id' => $workspace->id,
        'usage_period_id' => $period->id,
        'description' => 'newer',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/transactions");

    $response->assertSuccessful();
    expect($response->json('data.0.description'))->toBe('newer');
    expect($response->json('data.1.description'))->toBe('older');
});

test('non-member cannot list credit transactions', function () {
    [$owner, $workspace] = setupCreditWorkspace();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/credits/transactions");

    $response->assertForbidden();
});

test('unauthenticated user cannot list credit transactions', function () {
    [, $workspace] = setupCreditWorkspace();

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/credits/transactions");

    $response->assertUnauthorized();
});

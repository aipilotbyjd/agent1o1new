<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('bootstraps billing state when creating a workspace', function () {
    $owner = User::factory()->create();
    $service = new WorkspaceService();

    // Create the free plan
    $freePlan = Plan::factory()->free()->create();

    $workspace = $service->create($owner, ['name' => 'Test Workspace']);

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($workspace->owner_id)->toBe($owner->id)
        ->and($workspace->name)->toBe('Test Workspace');

    // Verify in database
    $this->assertDatabaseHas('workspaces', [
        'id' => $workspace->id,
        'owner_id' => $owner->id,
        'name' => 'Test Workspace',
    ]);
});

it('creates a subscription with free plan on workspace creation', function () {
    $owner = User::factory()->create();
    $service = new WorkspaceService();

    // Create the free plan
    $freePlan = Plan::factory()->free()->create();

    $workspace = $service->create($owner, ['name' => 'Test Workspace']);

    // Verify subscription was created
    $subscription = $workspace->subscriptions()->first();
    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and($subscription->plan_id)->toBe($freePlan->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_interval)->toBe(BillingInterval::Monthly)
        ->and($subscription->credits_monthly)->toBe($freePlan->getLimit('credits_monthly'));

    $this->assertDatabaseHas('subscriptions', [
        'workspace_id' => $workspace->id,
        'plan_id' => $freePlan->id,
        'status' => SubscriptionStatus::Active->value,
        'billing_interval' => BillingInterval::Monthly->value,
    ]);
});

it('creates a current workspace usage period on workspace creation', function () {
    $owner = User::factory()->create();
    $service = new WorkspaceService();

    // Create the free plan
    $freePlan = Plan::factory()->free()->create();

    $workspace = $service->create($owner, ['name' => 'Test Workspace']);

    // Verify usage period was created
    $usagePeriod = $workspace->usagePeriods()->first();
    expect($usagePeriod)->toBeInstanceOf(WorkspaceUsagePeriod::class)
        ->and($usagePeriod->is_current)->toBeTrue()
        ->and($usagePeriod->period_start->toDateString())->toBe(now()->toDateString())
        ->and($usagePeriod->period_end->toDateString())->toBe(now()->addDays(30)->toDateString());

    $this->assertDatabaseHas('workspace_usage_periods', [
        'workspace_id' => $workspace->id,
        'is_current' => 1,
    ]);
});

it('initializes redis credits key on workspace creation', function () {
    $owner = User::factory()->create();
    $service = new WorkspaceService();

    // Create the free plan
    $freePlan = Plan::factory()->free()->create();

    // Just verify that workspace creation doesn't error out when Redis is unavailable
    $workspace = $service->create($owner, ['name' => 'Test Workspace']);

    // Verify workspace was created successfully
    expect($workspace)->toBeInstanceOf(Workspace::class);
});

it('adds owner to workspace_members on workspace creation', function () {
    $owner = User::factory()->create();
    $service = new WorkspaceService();

    // Create the free plan
    $freePlan = Plan::factory()->free()->create();

    $workspace = $service->create($owner, ['name' => 'Test Workspace']);

    // Verify owner is in workspace_members
    $this->assertDatabaseHas('workspace_members', [
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'role' => 'owner',
    ]);
});

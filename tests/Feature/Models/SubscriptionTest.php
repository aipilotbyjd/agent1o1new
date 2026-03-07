<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts status and billing_interval to enums', function () {
    $subscription = Subscription::factory()->create();

    expect($subscription->status)->toBeInstanceOf(SubscriptionStatus::class)
        ->and($subscription->billing_interval)->toBeInstanceOf(BillingInterval::class);
});

it('belongs to a workspace and plan', function () {
    $subscription = Subscription::factory()->create();

    expect($subscription->workspace)->toBeInstanceOf(Workspace::class)
        ->and($subscription->plan)->toBeInstanceOf(Plan::class);
});

it('has many usage periods', function () {
    $subscription = Subscription::factory()->create();
    WorkspaceUsagePeriod::factory()->for($subscription)->for($subscription->workspace)->create();

    expect($subscription->usagePeriods)->toHaveCount(1);
});

it('identifies active subscriptions', function () {
    $active = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
    $canceled = Subscription::factory()->canceled()->create();

    expect($active->isActive())->toBeTrue()
        ->and($canceled->isActive())->toBeFalse();
});

it('identifies usable subscriptions', function () {
    $active = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
    $trialing = Subscription::factory()->trialing()->create();
    $canceled = Subscription::factory()->canceled()->create();

    expect($active->isUsable())->toBeTrue()
        ->and($trialing->isUsable())->toBeTrue()
        ->and($canceled->isUsable())->toBeFalse();
});

it('detects trial status', function () {
    $trialing = Subscription::factory()->trialing()->create();
    $expiredTrial = Subscription::factory()->create([
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($trialing->onTrial())->toBeTrue()
        ->and($expiredTrial->onTrial())->toBeFalse();
});

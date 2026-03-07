<?php

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts limits, features, and stripe_prices as arrays', function () {
    $plan = Plan::factory()->create();

    expect($plan->limits)->toBeArray()
        ->and($plan->features)->toBeArray()
        ->and($plan->is_active)->toBeBool();
});

it('has many subscriptions', function () {
    $plan = Plan::factory()->create();
    Subscription::factory()->for($plan)->create();

    expect($plan->subscriptions)->toHaveCount(1)
        ->and($plan->subscriptions->first())->toBeInstanceOf(Subscription::class);
});

it('returns a limit value via getLimit', function () {
    $plan = Plan::factory()->create(['limits' => ['workflows' => 10]]);

    expect($plan->getLimit('workflows'))->toBe(10)
        ->and($plan->getLimit('nonexistent'))->toBeNull();
});

it('checks feature availability via hasFeature', function () {
    $plan = Plan::factory()->create(['features' => ['api_access' => true, 'custom_branding' => false]]);

    expect($plan->hasFeature('api_access'))->toBeTrue()
        ->and($plan->hasFeature('custom_branding'))->toBeFalse()
        ->and($plan->hasFeature('nonexistent'))->toBeFalse();
});

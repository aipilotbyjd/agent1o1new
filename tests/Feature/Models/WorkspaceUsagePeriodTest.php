<?php

use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a workspace and subscription', function () {
    $period = WorkspaceUsagePeriod::factory()->create();

    expect($period->workspace)->toBeInstanceOf(Workspace::class)
        ->and($period->subscription)->toBeInstanceOf(Subscription::class);
});

it('casts dates and booleans correctly', function () {
    $period = WorkspaceUsagePeriod::factory()->create();

    expect($period->period_start)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($period->period_end)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($period->is_current)->toBeBool()
        ->and($period->credits_limit)->toBeInt();
});

it('calculates total available credits', function () {
    $period = WorkspaceUsagePeriod::factory()->create([
        'credits_limit' => 500,
        'credits_from_packs' => 100,
        'credits_rolled_over' => 50,
    ]);

    expect($period->totalAvailable())->toBe(650);
});

it('calculates remaining credits', function () {
    $period = WorkspaceUsagePeriod::factory()->create([
        'credits_limit' => 500,
        'credits_from_packs' => 0,
        'credits_rolled_over' => 0,
        'credits_used' => 300,
    ]);

    expect($period->creditsRemaining())->toBe(200);
});

it('returns zero remaining when overused', function () {
    $period = WorkspaceUsagePeriod::factory()->create([
        'credits_limit' => 100,
        'credits_from_packs' => 0,
        'credits_rolled_over' => 0,
        'credits_used' => 200,
    ]);

    expect($period->creditsRemaining())->toBe(0);
});

it('detects exhausted credits', function () {
    $exhausted = WorkspaceUsagePeriod::factory()->create([
        'credits_limit' => 100,
        'credits_used' => 100,
    ]);
    $available = WorkspaceUsagePeriod::factory()->create([
        'credits_limit' => 100,
        'credits_used' => 50,
    ]);

    expect($exhausted->isExhausted())->toBeTrue()
        ->and($available->isExhausted())->toBeFalse();
});

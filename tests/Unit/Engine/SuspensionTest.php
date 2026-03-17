<?php

use App\Engine\Data\Suspension;
use Carbon\CarbonImmutable;

test('suspension stores reason and resume time', function () {
    $resumeAt = CarbonImmutable::parse('2026-03-17 12:00:00');

    $suspension = new Suspension(
        reason: 'waiting_for_webhook',
        resumeAt: $resumeAt,
    );

    expect($suspension->reason)->toBe('waiting_for_webhook')
        ->and($suspension->resumeAt)->toBe($resumeAt)
        ->and($suspension->nodeOutput)->toBe([]);
});

test('suspension stores node output', function () {
    $resumeAt = CarbonImmutable::parse('2026-03-17 14:00:00');

    $suspension = new Suspension(
        reason: 'rate_limited',
        resumeAt: $resumeAt,
        nodeOutput: ['partial' => 'data', 'progress' => 50],
    );

    expect($suspension->nodeOutput)->toBe(['partial' => 'data', 'progress' => 50]);
});

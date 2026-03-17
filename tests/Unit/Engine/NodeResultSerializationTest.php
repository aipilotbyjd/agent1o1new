<?php

use App\Engine\NodeResult;
use App\Enums\ExecutionNodeStatus;

test('completed result survives toArray/fromArray round-trip', function () {
    $original = NodeResult::completed(['key' => 'value'], 42);

    $restored = NodeResult::fromArray($original->toArray());

    expect($restored->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($restored->output)->toBe(['key' => 'value'])
        ->and($restored->durationMs)->toBe(42)
        ->and($restored->error)->toBeNull();
});

test('failed result survives toArray/fromArray round-trip', function () {
    $original = NodeResult::failed('Something broke', 'ERR_CODE', 100);

    $restored = NodeResult::fromArray($original->toArray());

    expect($restored->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($restored->error)->toBe(['message' => 'Something broke', 'code' => 'ERR_CODE'])
        ->and($restored->durationMs)->toBe(100)
        ->and($restored->output)->toBeNull();
});

test('skipped result survives toArray/fromArray round-trip', function () {
    $original = NodeResult::skipped('Not needed');

    $restored = NodeResult::fromArray($original->toArray());

    expect($restored->status)->toBe(ExecutionNodeStatus::Skipped)
        ->and($restored->output)->toBe(['reason' => 'Not needed']);
});

test('result with active branches survives round-trip', function () {
    $original = new NodeResult(
        status: ExecutionNodeStatus::Completed,
        output: ['matched' => true],
        activeBranches: ['yes', 'maybe'],
        durationMs: 5,
    );

    $restored = NodeResult::fromArray($original->toArray());

    expect($restored->activeBranches)->toBe(['yes', 'maybe'])
        ->and($restored->loopItems)->toBeNull();
});

test('result with loop items survives round-trip', function () {
    $original = new NodeResult(
        status: ExecutionNodeStatus::Completed,
        output: ['count' => 3],
        loopItems: ['a', 'b', 'c'],
    );

    $restored = NodeResult::fromArray($original->toArray());

    expect($restored->loopItems)->toBe(['a', 'b', 'c'])
        ->and($restored->activeBranches)->toBeNull();
});

<?php

use App\Engine\Nodes\Apps\Util\UtilNode;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionNodeStatus;
use Illuminate\Support\Facades\Log;

function utilPayload(string $operation, array $config = [], array $inputData = []): NodePayload
{
    return new NodePayload(
        nodeId: 'test_node',
        nodeType: 'util',
        nodeName: 'Util',
        config: array_merge(['operation' => $operation], $config),
        inputData: $inputData,
    );
}

// --- Filter ---

it('filters items by equals condition', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('filter', [
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ],
    ], [
        'items' => [
            ['name' => 'Alice', 'status' => 'active'],
            ['name' => 'Bob', 'status' => 'inactive'],
            ['name' => 'Charlie', 'status' => 'active'],
        ],
    ]));

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['count'])->toBe(2)
        ->and($result->output['items'])->toHaveCount(2)
        ->and($result->output['items'][0]['name'])->toBe('Alice')
        ->and($result->output['items'][1]['name'])->toBe('Charlie');
});

it('filters items by multiple conditions', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('filter', [
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
            ['field' => 'age', 'operator' => 'gt', 'value' => 25],
        ],
    ], [
        'items' => [
            ['name' => 'Alice', 'status' => 'active', 'age' => 30],
            ['name' => 'Bob', 'status' => 'active', 'age' => 20],
            ['name' => 'Charlie', 'status' => 'inactive', 'age' => 35],
        ],
    ]));

    expect($result->output['count'])->toBe(1)
        ->and($result->output['items'][0]['name'])->toBe('Alice');
});

it('filters items with contains operator', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('filter', [
        'conditions' => [
            ['field' => 'email', 'operator' => 'contains', 'value' => '@example.com'],
        ],
    ], [
        'items' => [
            ['email' => 'alice@example.com'],
            ['email' => 'bob@other.com'],
        ],
    ]));

    expect($result->output['count'])->toBe(1);
});

it('filters items with not_equals and lt operators', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('filter', [
        'conditions' => [
            ['field' => 'score', 'operator' => 'lt', 'value' => 50],
            ['field' => 'type', 'operator' => 'not_equals', 'value' => 'excluded'],
        ],
    ], [
        'items' => [
            ['score' => 30, 'type' => 'included'],
            ['score' => 40, 'type' => 'excluded'],
            ['score' => 60, 'type' => 'included'],
        ],
    ]));

    expect($result->output['count'])->toBe(1)
        ->and($result->output['items'][0]['score'])->toBe(30);
});

// --- Aggregate ---

it('counts items', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('aggregate', [
        'aggregate_operation' => 'count',
        'field' => '',
    ], [
        'items' => [['a' => 1], ['a' => 2], ['a' => 3]],
    ]));

    expect($result->output['result'])->toBe(3);
});

it('sums a field', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('aggregate', [
        'aggregate_operation' => 'sum',
        'field' => 'amount',
    ], [
        'items' => [
            ['amount' => 10],
            ['amount' => 20],
            ['amount' => 30],
        ],
    ]));

    expect($result->output['result'])->toBe(60);
});

it('averages a field', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('aggregate', [
        'aggregate_operation' => 'average',
        'field' => 'score',
    ], [
        'items' => [
            ['score' => 10],
            ['score' => 20],
            ['score' => 30],
        ],
    ]));

    expect($result->output['result'])->toEqual(20);
});

it('finds min and max of a field', function () {
    $node = new UtilNode;
    $items = [['v' => 5], ['v' => 1], ['v' => 9]];

    $min = $node->handle(utilPayload('aggregate', ['aggregate_operation' => 'min', 'field' => 'v'], ['items' => $items]));
    $max = $node->handle(utilPayload('aggregate', ['aggregate_operation' => 'max', 'field' => 'v'], ['items' => $items]));

    expect($min->output['result'])->toBe(1)
        ->and($max->output['result'])->toBe(9);
});

it('returns zero for aggregate on empty items', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('aggregate', [
        'aggregate_operation' => 'average',
        'field' => 'x',
    ], ['items' => []]));

    expect($result->output['result'])->toBe(0);
});

// --- JSON Parse ---

it('parses a valid json string', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('json_parse', [], [
        'json_string' => '{"name":"Alice","age":30}',
    ]));

    expect($result->output['data'])->toBe(['name' => 'Alice', 'age' => 30]);
});

it('fails on invalid json', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('json_parse', [], [
        'json_string' => 'not json',
    ]));

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('UTIL_ERROR');
});

// --- Template ---

it('renders a template with variables', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('template', [
        'template' => 'Hello {{name}}, your order #{{order_id}} is confirmed.',
    ], [
        'variables' => ['name' => 'Alice', 'order_id' => '12345'],
    ]));

    expect($result->output['text'])->toBe('Hello Alice, your order #12345 is confirmed.');
});

it('leaves unmatched placeholders as-is', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('template', [
        'template' => 'Hi {{name}}, {{missing}} here.',
    ], [
        'variables' => ['name' => 'Bob'],
    ]));

    expect($result->output['text'])->toBe('Hi Bob, {{missing}} here.');
});

// --- Logger ---

it('logs a message at the configured level', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Something happened', ['key' => 'value']);

    $node = new UtilNode;

    $result = $node->handle(utilPayload('logger', [
        'level' => 'warning',
    ], [
        'message' => 'Something happened',
        'data' => ['key' => 'value'],
    ]));

    expect($result->output['logged'])->toBeTrue()
        ->and($result->output['timestamp'])->toBeString();
});

it('defaults to info level for invalid log level', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('test', []);

    $node = new UtilNode;

    $result = $node->handle(utilPayload('logger', [
        'level' => 'invalid_level',
    ], [
        'message' => 'test',
    ]));

    expect($result->output['logged'])->toBeTrue();
});

// --- Error Handler ---

it('passes through input data in error handler', function () {
    $node = new UtilNode;

    $inputData = ['foo' => 'bar', 'count' => 42];

    $result = $node->handle(utilPayload('error_handler', [
        'on_error' => 'continue',
    ], $inputData));

    expect($result->output)->toBe($inputData);
});

// --- Unknown Operation ---

it('returns failed result for unknown operation', function () {
    $node = new UtilNode;

    $result = $node->handle(utilPayload('nonexistent'));

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('UTIL_ERROR');
});

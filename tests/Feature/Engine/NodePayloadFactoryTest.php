<?php

use App\Engine\Data\ExpressionParser;
use App\Engine\Data\OutputBuffer;
use App\Engine\RunContext;
use App\Engine\Runners\NodePayload;
use App\Engine\Runners\NodePayloadFactory;
use App\Engine\WorkflowGraph;

function makeMinimalGraph(array $nodeMap = []): WorkflowGraph
{
    return new WorkflowGraph(
        nodeMap: $nodeMap,
        successors: [],
        predecessors: [],
        inDegree: [],
        startNodes: array_keys($nodeMap),
        compiledExpressions: [],
        downstreamConsumers: [],
        edgeMap: [],
    );
}

test('build returns a NodePayload with resolved config', function () {
    $expressionParser = Mockery::mock(ExpressionParser::class);

    $graph = makeMinimalGraph([
        'node_1' => [
            'type' => 'transform',
            'name' => 'My Transform',
            'data' => ['key' => 'raw_value'],
        ],
    ]);

    $outputBuffer = Mockery::mock(OutputBuffer::class);
    $outputBuffer->shouldReceive('get')->andReturn(null);

    $context = new RunContext(
        graph: $graph,
        outputs: $outputBuffer,
        executionId: 123,
        variables: ['var1' => 'val1'],
    );

    $factory = new NodePayloadFactory($expressionParser);
    $payload = $factory->build('node_1', $graph, $context);

    expect($payload)->toBeInstanceOf(NodePayload::class)
        ->and($payload->nodeId)->toBe('node_1')
        ->and($payload->nodeType)->toBe('transform')
        ->and($payload->nodeName)->toBe('My Transform')
        ->and($payload->config)->toBe(['key' => 'raw_value'])
        ->and($payload->credentials)->toBeNull()
        ->and($payload->variables)->toBe(['var1' => 'val1'])
        ->and($payload->executionMeta)->toBe([
            'execution_id' => 123,
            'trigger_data' => [],
        ]);
});

test('build resolves compiled config via expression parser', function () {
    $expressionParser = Mockery::mock(ExpressionParser::class);

    $compiledConfig = ['url' => [['type' => 'literal', 'value' => 'https://api.example.com']]];

    $graph = new WorkflowGraph(
        nodeMap: [
            'node_2' => [
                'type' => 'http_request',
                'name' => 'API Call',
            ],
        ],
        successors: [],
        predecessors: [],
        inDegree: [],
        startNodes: ['node_2'],
        compiledExpressions: ['node_2' => $compiledConfig],
        downstreamConsumers: [],
        edgeMap: [],
    );

    $outputBuffer = Mockery::mock(OutputBuffer::class);
    $outputBuffer->shouldReceive('get')->andReturn(null);

    $context = new RunContext(
        graph: $graph,
        outputs: $outputBuffer,
        executionId: 456,
    );

    $expressionParser->shouldReceive('resolveConfig')
        ->with($compiledConfig, Mockery::any())
        ->andReturn(['url' => 'https://api.example.com']);

    $factory = new NodePayloadFactory($expressionParser);
    $payload = $factory->build('node_2', $graph, $context);

    expect($payload->config)->toBe(['url' => 'https://api.example.com']);
});

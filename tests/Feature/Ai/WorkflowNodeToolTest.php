<?php

use App\Ai\Tools\WorkflowNodeTool;
use Laravel\Ai\Ai;
use Laravel\Ai\Tools\Request;

it('executes a workflow node successfully as a tool', function () {
    Ai::fakeAgent(\App\Ai\Agents\ChatAgent::class, ['Tool output!']);

    config(['ai.providers.openai.key' => 'sk-test-openai']);

    $tool = new WorkflowNodeTool(
        nodeType: 'ai.chat_completion',
        toolName: 'ai_chat_tool',
        toolDescription: 'A dummy tool',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string'],
            ],
        ],
        credentials: ['api_key' => '123']
    );

    $request = new Request([
        'prompt' => 'hello world',
    ]);

    $result = $tool->handle($request);

    // Assert it returns a JSON string with the output
    $json = json_decode((string) $result, true);

    expect($json)->toBeArray()
        ->and($json['text'])->toBe('Tool output!');
});

it('handles node execution failure gracefully', function () {
    $tool = new WorkflowNodeTool(
        nodeType: 'ai.chat_completion',
        toolName: 'test_dummy_tool',
        toolDescription: 'A dummy tool',
        inputSchema: [],
        credentials: []
    );

    $request = new Request([
        'operation' => 'nonexistent', // will cause failure
    ]);

    $result = $tool->handle($request);

    $json = json_decode((string) $result, true);

    expect($json)->toHaveKey('error')
        ->and($json['error'])->toContain('Unknown operation');
});

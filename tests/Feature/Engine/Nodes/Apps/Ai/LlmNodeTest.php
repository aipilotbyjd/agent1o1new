<?php

use App\Ai\Agents\ChatAgent;
use App\Engine\Nodes\Apps\Ai\LlmNode;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionNodeStatus;
use Laravel\Ai\Ai;

function llmPayload(array $config = [], array $inputData = [], ?array $credentials = null): NodePayload
{
    return new NodePayload(
        nodeId: 'node-1',
        nodeType: 'ai',
        nodeName: 'AI',
        config: array_merge(['provider' => 'openai'], $config),
        inputData: $inputData,
        credentials: $credentials ?? ['api_key' => 'sk-test-key'],
    );
}

beforeEach(function () {
    config(['ai.providers.openai.key' => 'sk-test-openai']);
    config(['ai.providers.anthropic.key' => 'sk-test-anthropic']);
});

it('handles chat completion operation using openai', function () {
    Ai::fakeAgent(ChatAgent::class, ['Hello! How can I help?']);

    $payload = llmPayload(
        config: ['operation' => 'chat_completion', 'system_prompt' => 'You are helpful.', 'model' => 'gpt-4o-mini'],
        inputData: ['prompt' => 'Hi there'],
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['text'])->toBe('Hello! How can I help?')
        ->and($result->output['model'])->toBe('gpt-4o-mini')
        ->and($result->output['provider'])->toBe('openai');
});

it('handles chat completion using anthropic', function () {
    Ai::fakeAgent(ChatAgent::class, ['Hello from Anthropic!']);

    $payload = llmPayload(
        config: ['operation' => 'chat_completion', 'provider' => 'anthropic', 'model' => 'claude-3-opus'],
        inputData: ['prompt' => 'Hi there'],
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['text'])->toBe('Hello from Anthropic!')
        ->and($result->output['provider'])->toBe('anthropic');
});

it('returns failed result for unknown operation', function () {
    $payload = llmPayload(config: ['operation' => 'nonexistent']);

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('AI_ERROR');
});

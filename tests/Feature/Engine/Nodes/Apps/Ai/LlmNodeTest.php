<?php

use App\Ai\Agents\ChatAgent;
use App\Engine\Nodes\Apps\Ai\LlmNode;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionNodeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

uses(RefreshDatabase::class);

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

it('handles text classifier operation', function () {
    Ai::fakeAgent(\App\Ai\Agents\TextClassifierAgent::class, [['category' => 'spam', 'confidence' => 0.95]]);

    $payload = llmPayload(
        config: ['operation' => 'text_classifier', 'categories' => ['spam', 'valid']],
        inputData: ['text' => 'Buy cheap meds!']
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['category'])->toBe('spam')
        ->and($result->output['confidence'])->toBe(0.95);
});

it('handles summarizer operation', function () {
    Ai::fakeAgent(\App\Ai\Agents\SummarizerAgent::class, ['A brief summary']);

    $payload = llmPayload(
        config: ['operation' => 'summarizer'],
        inputData: ['text' => 'Long text']
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['summary'])->toBe('A brief summary');
});

it('handles sentiment operation', function () {
    Ai::fakeAgent(\App\Ai\Agents\SentimentAgent::class, [['sentiment' => 'positive', 'score' => 0.9, 'emotions' => ['joy']]]);

    $payload = llmPayload(
        config: ['operation' => 'sentiment'],
        inputData: ['text' => 'I love this!']
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['sentiment'])->toBe('positive')
        ->and($result->output['score'])->toBe(0.9)
        ->and($result->output['emotions'])->toBe(['joy']);
});

it('handles embeddings operation', function () {
    Ai::fakeEmbeddings([
        [[0.1, 0.2, 0.3]],
    ]);

    $payload = llmPayload(
        config: ['operation' => 'embeddings'],
        inputData: ['text' => 'Test text']
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['embeddings'][0])->toBe([0.1, 0.2, 0.3]);
});

it('handles image generation operation', function () {
    // Fake the built-in HTTP client if Ai::fakeImages() isn't as easily setup for storing base64
    // Wait, Ai::fakeImages defaults to returning base64 instances.
    // The LlmNode attempts to store the image to the disk. I should fake the disk.
    \Illuminate\Support\Facades\Storage::fake('local');

    Ai::fakeImages();

    $payload = llmPayload(
        config: ['operation' => 'image_generation'],
        inputData: ['prompt' => 'A flying cat']
    );

    $result = (new LlmNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['images'][0])->toContain('.png')
        ->and($result->output['image_count'])->toBe(1);
});

it('handles ai_agent operation', function () {
    Ai::fakeAgent(\App\Ai\Agents\WorkflowAgent::class, ['I used the tools.']);

    // Setup dummy execution so that the ai_agent can log steps properly
    $workspace = \App\Models\Workspace::factory()->create();
    $workflow = \App\Models\Workflow::factory()->create(['workspace_id' => $workspace->id]);
    $execution = \App\Models\Execution::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
    ]);

    $payload = llmPayload(
        config: ['operation' => 'agent'],
        inputData: [
            'prompt' => 'Use tools',
            'tools' => [['name' => 'mock_tool', 'config' => [], 'credential_id' => null]],
        ]
    );
    // Explicitly set executionId since it's needed for step logging
    $payload->executionId = $execution->id;

    $result = (new LlmNode)->handle($payload);

    if ($result->status === ExecutionNodeStatus::Failed) {
        dd($result->error);
    }

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['response'])->toBe('I used the tools.');
});

<?php

use App\Engine\Nodes\Apps\OpenAi\OpenAiNode;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionNodeStatus;
use Illuminate\Support\Facades\Http;

function openAiPayload(array $config = [], array $inputData = [], ?array $credentials = null): NodePayload
{
    return new NodePayload(
        nodeId: 'node-1',
        nodeType: 'openai',
        nodeName: 'OpenAI',
        config: $config,
        inputData: $inputData,
        credentials: $credentials ?? ['api_key' => 'sk-test-key'],
    );
}

it('handles chat completion operation', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => ['content' => 'Hello! How can I help?'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
            'model' => 'gpt-4o-mini',
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'chat_completion', 'system_prompt' => 'You are helpful.'],
        inputData: ['prompt' => 'Hi there'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['text'])->toBe('Hello! How can I help?')
        ->and($result->output['usage']['total_tokens'])->toBe(15)
        ->and($result->output['model'])->toBe('gpt-4o-mini')
        ->and($result->output['finish_reason'])->toBe('stop');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test-key')
            && count($request->data()['messages']) === 2
            && $request->data()['messages'][0]['role'] === 'system'
            && $request->data()['messages'][1]['role'] === 'user';
    });
});

it('handles chat completion without system prompt', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            'model' => 'gpt-4o-mini',
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'chat_completion'],
        inputData: ['prompt' => 'Hello'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed);

    Http::assertSent(function ($request) {
        return count($request->data()['messages']) === 1
            && $request->data()['messages'][0]['role'] === 'user';
    });
});

it('handles text classifier operation', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"category":"spam","confidence":0.92}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            'model' => 'gpt-4o-mini',
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'text_classifier', 'categories' => ['spam', 'ham', 'promo']],
        inputData: ['text' => 'Buy now! Limited offer!'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['category'])->toBe('spam')
        ->and($result->output['confidence'])->toBe(0.92);
});

it('handles summarizer operation with paragraph format', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'This is a summary.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            'model' => 'gpt-4o-mini',
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'summarizer', 'format' => 'paragraph', 'max_length' => 100],
        inputData: ['text' => 'A very long text that needs summarizing...'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['summary'])->toBe('This is a summary.');
});

it('handles summarizer operation with bullets format', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "- Point 1\n- Point 2"], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            'model' => 'gpt-4o-mini',
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'summarizer', 'format' => 'bullets'],
        inputData: ['text' => 'Some long text.'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['summary'])->toContain('- Point 1');

    Http::assertSent(function ($request) {
        return str_contains($request->data()['messages'][0]['content'], 'bullet points');
    });
});

it('handles embeddings operation', function () {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'embeddings'],
        inputData: ['text' => 'Hello world'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['embeddings'])->toBe([[0.1, 0.2, 0.3]])
        ->and($result->output['usage']['prompt_tokens'])->toBe(5);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/embeddings'
            && $request->data()['model'] === 'text-embedding-3-small';
    });
});

it('handles image generation operation', function () {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'data' => [
                ['url' => 'https://example.com/image1.png', 'revised_prompt' => 'A beautiful sunset over the ocean'],
            ],
        ]),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'image_generation', 'size' => '1024x1024'],
        inputData: ['prompt' => 'A sunset over the ocean'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['images'])->toBe(['https://example.com/image1.png'])
        ->and($result->output['revised_prompt'])->toBe('A beautiful sunset over the ocean');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/images/generations'
            && $request->data()['model'] === 'dall-e-3'
            && $request->data()['quality'] === 'standard'
            && $request->data()['n'] === 1;
    });
});

it('returns failed result for unknown operation', function () {
    $payload = openAiPayload(config: ['operation' => 'nonexistent']);

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('OPENAI_ERROR');
});

it('returns failed result on api error', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
    ]);

    $payload = openAiPayload(
        config: ['operation' => 'chat_completion', 'maxRetries' => 0],
        inputData: ['prompt' => 'Hello'],
    );

    $result = (new OpenAiNode)->handle($payload);

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('OPENAI_ERROR');
});

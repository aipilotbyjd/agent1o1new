<?php

namespace App\Engine\Nodes\Apps\OpenAi;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OpenAiNode extends AppNode
{
    private const CHAT_URL = 'https://api.openai.com/v1/chat/completions';

    private const EMBEDDINGS_URL = 'https://api.openai.com/v1/embeddings';

    private const IMAGES_URL = 'https://api.openai.com/v1/images/generations';

    protected function errorCode(): string
    {
        return 'OPENAI_ERROR';
    }

    protected function operations(): array
    {
        return [
            'chat_completion' => $this->chatCompletionOperation(...),
            'text_classifier' => $this->textClassifier(...),
            'summarizer' => $this->summarizer(...),
            'embeddings' => $this->embeddings(...),
            'image_generation' => $this->imageGeneration(...),
        ];
    }

    private function client(NodePayload $payload, int $timeout = 60): PendingRequest
    {
        $apiKey = $payload->credentials['api_key'] ?? '';

        return Http::timeout($timeout)
            ->withToken($apiKey)
            ->withHeaders(['Content-Type' => 'application/json']);
    }

    /**
     * Shared chat completion call used by multiple operations.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    private function chatCompletion(NodePayload $payload, array $messages, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): array
    {
        $config = $payload->config;

        $body = [
            'model' => $model ?? ($config['model'] ?? 'gpt-4o-mini'),
            'messages' => $messages,
            'temperature' => $temperature ?? (float) ($config['temperature'] ?? 0.7),
            'max_tokens' => $maxTokens ?? (int) ($config['max_tokens'] ?? 1024),
        ];

        $response = $this->client($payload)->post(self::CHAT_URL, $body);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletionOperation(NodePayload $payload): array
    {
        $config = $payload->config;
        $prompt = $payload->inputData['prompt'] ?? $config['prompt'] ?? '';

        $messages = [];

        $systemPrompt = $config['system_prompt'] ?? null;
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = $this->chatCompletion($payload, $messages);

        $choice = $data['choices'][0] ?? [];

        return [
            'text' => $choice['message']['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $data['usage']['total_tokens'] ?? 0,
            ],
            'model' => $data['model'] ?? '',
            'finish_reason' => $choice['finish_reason'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textClassifier(NodePayload $payload): array
    {
        $config = $payload->config;
        $text = $payload->inputData['text'] ?? $config['text'] ?? '';
        $categories = $config['categories'] ?? [];
        $model = $config['model'] ?? 'gpt-4o-mini';

        $categoriesList = implode(', ', $categories);
        $systemPrompt = "You are a text classifier. Classify the given text into exactly one of the following categories: {$categoriesList}. Respond with only valid JSON in this format: {\"category\": \"...\", \"confidence\": 0.95}. Do not include any other text.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $text],
        ];

        $data = $this->chatCompletion($payload, $messages, $model, 0.3, 256);

        $content = $data['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($content, true) ?? [];

        return [
            'category' => $parsed['category'] ?? '',
            'confidence' => (float) ($parsed['confidence'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizer(NodePayload $payload): array
    {
        $config = $payload->config;
        $text = $payload->inputData['text'] ?? $config['text'] ?? '';
        $format = $config['format'] ?? 'paragraph';
        $maxLength = (int) ($config['max_length'] ?? 200);
        $model = $config['model'] ?? 'gpt-4o-mini';

        $formatInstruction = $format === 'bullets'
            ? 'Use bullet points.'
            : 'Write as a single paragraph.';

        $systemPrompt = "You are a summarizer. Summarize the given text within {$maxLength} words. {$formatInstruction} Respond with only the summary, no additional text.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $text],
        ];

        $data = $this->chatCompletion($payload, $messages, $model, 0.5, $maxLength * 4);

        return [
            'summary' => $data['choices'][0]['message']['content'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddings(NodePayload $payload): array
    {
        $config = $payload->config;
        $input = $payload->inputData['text'] ?? $config['text'] ?? '';
        $model = $config['model'] ?? 'text-embedding-3-small';

        $response = $this->client($payload)->post(self::EMBEDDINGS_URL, [
            'model' => $model,
            'input' => $input,
        ]);

        $response->throw();

        $data = $response->json();

        return [
            'embeddings' => array_map(
                fn (array $item) => $item['embedding'],
                $data['data'] ?? [],
            ),
            'usage' => $data['usage'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageGeneration(NodePayload $payload): array
    {
        $config = $payload->config;
        $prompt = $payload->inputData['prompt'] ?? $config['prompt'] ?? '';

        $response = $this->client($payload, 120)->post(self::IMAGES_URL, [
            'model' => $config['model'] ?? 'dall-e-3',
            'prompt' => $prompt,
            'size' => $config['size'] ?? '1024x1024',
            'quality' => $config['quality'] ?? 'standard',
            'n' => (int) ($config['n'] ?? 1),
        ]);

        $response->throw();

        $data = $response->json();

        return [
            'images' => array_map(
                fn (array $item) => $item['url'] ?? '',
                $data['data'] ?? [],
            ),
            'revised_prompt' => $data['data'][0]['revised_prompt'] ?? '',
        ];
    }
}

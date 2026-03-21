<?php

namespace App\Engine\Nodes\Apps\Ai;

use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\SentimentAgent;
use App\Ai\Agents\SummarizerAgent;
use App\Ai\Agents\TextClassifierAgent;
use App\Ai\Agents\WorkflowAgent;
use App\Ai\Tools\WorkflowNodeTool;
use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use App\Models\Node;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Image;

/**
 * Multi-provider AI node powered by the Laravel AI SDK.
 *
 * Supports all operations across all providers (OpenAI, Anthropic, Gemini, Groq, etc.)
 * and includes an autonomous AI Agent mode with tool-calling capabilities.
 */
class LlmNode extends AppNode
{
    protected function errorCode(): string
    {
        return 'AI_ERROR';
    }

    protected function operations(): array
    {
        return [
            'chat_completion' => $this->chatCompletion(...),
            'text_classifier' => $this->textClassifier(...),
            'summarizer' => $this->summarizer(...),
            'sentiment' => $this->sentiment(...),
            'embeddings' => $this->embeddings(...),
            'image_generation' => $this->imageGeneration(...),
            'agent' => $this->agent(...),
        ];
    }

    /**
     * Resolve the Lab enum for the configured provider.
     */
    private function resolveProvider(NodePayload $payload): ?Lab
    {
        $provider = $payload->config['provider'] ?? null;

        if ($provider === null) {
            return null; // Use SDK default
        }

        return Lab::tryFrom($provider);
    }

    /**
     * Get the model string from config.
     */
    private function resolveModel(NodePayload $payload): ?string
    {
        return $payload->config['model'] ?? null;
    }

    /**
     * Multi-provider chat completion using the Laravel AI SDK.
     *
     * @return array<string, mixed>
     */
    private function chatCompletion(NodePayload $payload): array
    {
        $prompt = $payload->inputData['prompt'] ?? $payload->config['prompt'] ?? '';
        $systemPrompt = $payload->config['system_prompt'] ?? 'You are a helpful assistant.';

        $agent = new ChatAgent($systemPrompt);

        $response = $agent->prompt(
            $prompt,
            provider: $this->resolveProvider($payload),
            model: $this->resolveModel($payload),
        );

        return [
            'text' => (string) $response,
            'model' => $this->resolveModel($payload) ?? config('ai.default', 'openai'),
            'provider' => $payload->config['provider'] ?? 'openai',
        ];
    }

    /**
     * Classify text into categories with structured output.
     *
     * @return array<string, mixed>
     */
    private function textClassifier(NodePayload $payload): array
    {
        $text = $payload->inputData['text'] ?? $payload->config['text'] ?? '';
        $categories = $payload->config['categories'] ?? [];

        $agent = new TextClassifierAgent($categories);

        $response = $agent->prompt(
            $text,
            provider: $this->resolveProvider($payload),
            model: $this->resolveModel($payload),
        );

        return [
            'category' => $response['category'] ?? '',
            'confidence' => (float) ($response['confidence'] ?? 0),
        ];
    }

    /**
     * Summarize text with format control.
     *
     * @return array<string, mixed>
     */
    private function summarizer(NodePayload $payload): array
    {
        $text = $payload->inputData['text'] ?? $payload->config['text'] ?? '';
        $format = $payload->config['format'] ?? 'paragraph';
        $maxLength = (int) ($payload->config['max_length'] ?? 200);

        $agent = new SummarizerAgent($format, $maxLength);

        $response = $agent->prompt(
            $text,
            provider: $this->resolveProvider($payload),
            model: $this->resolveModel($payload),
        );

        return [
            'summary' => (string) $response,
        ];
    }

    /**
     * Analyze sentiment of text.
     *
     * @return array<string, mixed>
     */
    private function sentiment(NodePayload $payload): array
    {
        $text = $payload->inputData['text'] ?? $payload->config['text'] ?? '';

        $agent = new SentimentAgent;

        $response = $agent->prompt(
            $text,
            provider: $this->resolveProvider($payload),
            model: $this->resolveModel($payload),
        );

        return [
            'sentiment' => $response['sentiment'] ?? 'neutral',
            'score' => (float) ($response['score'] ?? 0.5),
            'emotions' => $response['emotions'] ?? [],
        ];
    }

    /**
     * Generate vector embeddings using the SDK.
     *
     * @return array<string, mixed>
     */
    private function embeddings(NodePayload $payload): array
    {
        $input = $payload->inputData['text'] ?? $payload->config['text'] ?? '';
        $inputs = is_array($input) ? $input : [$input];

        $provider = $this->resolveProvider($payload);
        $model = $this->resolveModel($payload);

        $builder = Embeddings::for($inputs);

        if ($payload->config['dimensions'] ?? null) {
            $builder = $builder->dimensions((int) $payload->config['dimensions']);
        }

        $response = $provider
            ? $builder->generate($provider, $model)
            : $builder->generate();

        return [
            'embeddings' => $response->embeddings,
        ];
    }

    /**
     * Generate images using the SDK.
     *
     * @return array<string, mixed>
     */
    private function imageGeneration(NodePayload $payload): array
    {
        $prompt = $payload->inputData['prompt'] ?? $payload->config['prompt'] ?? '';
        $provider = $this->resolveProvider($payload);
        $model = $this->resolveModel($payload);

        $image = Image::of($prompt);

        $result = $provider
            ? $image->generate($provider, $model)
            : $image->generate();

        // Store the generated image and return the path
        $storedPath = $result->store('ai-images', config('filesystems.default'));

        return [
            'images' => [$storedPath ?: ''],
            'image_count' => $result->count(),
        ];
    }

    /**
     * Autonomous AI Agent that uses the tool-calling loop.
     *
     * @return array<string, mixed>
     */
    private function agent(NodePayload $payload): array
    {
        $prompt = $payload->inputData['prompt'] ?? '';
        $systemPrompt = $payload->config['system_prompt'] ?? 'You are a helpful AI assistant that can use tools to accomplish tasks.';
        $toolNodeTypes = $payload->config['tools'] ?? [];

        // Build WorkflowNodeTool instances for each configured tool
        $tools = $this->buildTools($toolNodeTypes, $payload);

        $agent = new WorkflowAgent($systemPrompt, $tools);

        $response = $agent->prompt(
            $prompt,
            provider: $this->resolveProvider($payload),
            model: $this->resolveModel($payload),
        );

        return [
            'response' => (string) $response,
            'provider' => $payload->config['provider'] ?? 'openai',
            'model' => $this->resolveModel($payload) ?? 'gpt-4o',
        ];
    }

    /**
     * Build WorkflowNodeTool instances from the configured tool node types.
     *
     * @param  list<string>  $nodeTypes
     * @return list<WorkflowNodeTool>
     */
    private function buildTools(array $nodeTypes, NodePayload $payload): array
    {
        $tools = [];

        foreach ($nodeTypes as $nodeType) {
            $nodeDefinition = Node::query()
                ->where('type', $nodeType)
                ->first();

            if ($nodeDefinition === null) {
                continue;
            }

            $toolName = str_replace('.', '_', $nodeType);

            $tools[] = new WorkflowNodeTool(
                nodeType: $nodeType,
                toolName: $toolName,
                toolDescription: $nodeDefinition->description ?? "Use the {$nodeDefinition->name} tool",
                inputSchema: $nodeDefinition->input_schema ?? $nodeDefinition->config_schema ?? [],
                credentials: $payload->credentials,
            );
        }

        return $tools;
    }
}

<?php

namespace App\Services;

use Laravel\Ai\Enums\Lab;

/**
 * Resolves AI provider strings from user config / credentials to SDK Lab enums.
 */
class AiProviderService
{
    /**
     * Map of provider names to their Lab enum values.
     *
     * @var array<string, Lab>
     */
    private static array $providerMap = [
        'openai' => Lab::OpenAI,
        'anthropic' => Lab::Anthropic,
        'gemini' => Lab::Gemini,
        'groq' => Lab::Groq,
        'xai' => Lab::XAI,
        'mistral' => Lab::Mistral,
        'ollama' => Lab::Ollama,
        'cohere' => Lab::Cohere,
        'deepseek' => Lab::DeepSeek,
        'azure' => Lab::Azure,
    ];

    /**
     * Resolve a provider string to a Lab enum instance.
     */
    public static function resolve(string $provider): ?Lab
    {
        return self::$providerMap[$provider] ?? Lab::tryFrom($provider);
    }

    /**
     * Get all supported provider names.
     *
     * @return list<string>
     */
    public static function supportedProviders(): array
    {
        return array_keys(self::$providerMap);
    }

    /**
     * Get the default model for a given provider.
     */
    public static function defaultModel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-sonnet-4-20250514',
            'gemini' => 'gemini-2.0-flash',
            'groq' => 'llama-3.3-70b-versatile',
            'xai' => 'grok-3-mini',
            'mistral' => 'mistral-large-latest',
            'ollama' => 'llama3.2',
            'cohere' => 'command-a-03-2025',
            'deepseek' => 'deepseek-chat',
            default => 'gpt-4o-mini',
        };
    }
}

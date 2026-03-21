<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.3)]
class SentimentAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are a sentiment analysis expert. Analyze the sentiment of the given text. Determine whether the overall sentiment is positive, negative, or neutral. Provide a confidence score and list the primary emotions detected.';
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sentiment' => $schema->string()->required(),
            'score' => $schema->number()->min(0)->max(1)->required(),
            'emotions' => $schema->array()->items($schema->string())->required(),
        ];
    }
}

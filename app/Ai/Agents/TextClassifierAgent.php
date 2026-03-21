<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.3)]
class TextClassifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        private array $categories = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $categoriesList = implode(', ', $this->categories);

        return "You are a text classifier. Classify the given text into exactly one of the following categories: {$categoriesList}. Provide your confidence score from 0 to 1.";
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}

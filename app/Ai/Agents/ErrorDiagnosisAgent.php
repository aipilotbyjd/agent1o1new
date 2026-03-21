<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.3)]
#[MaxSteps(5)]
#[Timeout(60)]
class ErrorDiagnosisAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private string $errorMessage,
        private string $nodeType,
        private array $nodeConfig,
        private array $inputData,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $configJson = json_encode($this->nodeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $inputJson = json_encode($this->inputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
        You are a workflow debugging expert for a workflow automation engine.

        A workflow node has failed with the following details:

        Node Type: {$this->nodeType}
        Error Message: {$this->errorMessage}

        Node Configuration:
        {$configJson}

        Input Data:
        {$inputJson}

        Analyze the error, diagnose the root cause, and provide actionable fix suggestions.
        Each suggestion should include a title, description, and optionally a corrected config object.
        PROMPT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'diagnosis' => $schema->string()->required(),
            'suggestions' => $schema->array()->items(
                $schema->object([
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'fix_config' => $schema->object(),
                ])
            )->required(),
        ];
    }
}

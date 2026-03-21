<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.3)]
class WorkflowDescriptionAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a workflow documentation assistant. Given a workflow's node and edge data in JSON format, generate a clear, concise human-readable description of what the workflow does.

        Format your response as:
        1. A brief one-sentence summary
        2. A step-by-step breakdown of the workflow's execution flow

        Be specific about what each node does based on its type and configuration. Use simple language suitable for non-technical users.
        PROMPT;
    }
}

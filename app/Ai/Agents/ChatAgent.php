<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class ChatAgent implements Agent
{
    use Promptable;

    public function __construct(
        private string $systemPrompt = 'You are a helpful assistant.',
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return $this->systemPrompt;
    }
}

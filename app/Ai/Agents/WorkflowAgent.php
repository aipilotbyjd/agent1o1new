<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(15)]
#[Timeout(180)]
class WorkflowAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  list<\Laravel\Ai\Contracts\Tool>  $availableTools
     */
    public function __construct(
        private string $systemPrompt,
        private array $availableTools = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return $this->systemPrompt;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return $this->availableTools;
    }
}

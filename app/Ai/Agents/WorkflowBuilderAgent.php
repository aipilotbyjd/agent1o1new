<?php

namespace App\Ai\Agents;

use App\Ai\Tools\InspectNodeSchemaTool;
use App\Ai\Tools\ListAvailableNodesTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.3)]
#[MaxSteps(10)]
#[Timeout(120)]
class WorkflowBuilderAgent implements Agent, HasTools, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a workflow automation expert. Your job is to generate workflow definitions in JSON format from natural language descriptions.

        A workflow consists of:
        1. **nodes** — an array of node objects, each with: `key` (unique string), `type` (from available node types), `name` (display name), `config` (matches the node's config_schema), `position` ({x, y}).
        2. **edges** — an array of edge objects, each with: `source` (node key), `target` (node key), `source_handle` (usually "output"), `target_handle` (usually "input").

        Steps:
        1. First, use the list_available_nodes tool to discover what node types are available.
        2. For each node type you plan to use, use the inspect_node_schema tool to understand its configuration.
        3. Build the workflow JSON with properly configured nodes and edges connecting them in the right order.
        4. The first node should typically be a trigger (webhook, schedule, or manual).
        5. Position nodes left-to-right with ~250px horizontal spacing.

        Important:
        - Use real node types from the available nodes list — don't make up types.
        - Always check the config_schema before setting config values.
        - Each node must have a unique `key`.
        PROMPT;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [
            new ListAvailableNodesTool,
            new InspectNodeSchemaTool,
        ];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_name' => $schema->string()->required(),
            'workflow_description' => $schema->string()->required(),
            'nodes' => $schema->array()->items(
                $schema->object([
                    'key' => $schema->string()->required(),
                    'type' => $schema->string()->required(),
                    'name' => $schema->string()->required(),
                    'config' => $schema->object(),
                    'position' => $schema->object([
                        'x' => $schema->number()->required(),
                        'y' => $schema->number()->required(),
                    ]),
                ])
            )->required(),
            'edges' => $schema->array()->items(
                $schema->object([
                    'source' => $schema->string()->required(),
                    'target' => $schema->string()->required(),
                    'source_handle' => $schema->string()->required(),
                    'target_handle' => $schema->string()->required(),
                ])
            )->required(),
        ];
    }
}

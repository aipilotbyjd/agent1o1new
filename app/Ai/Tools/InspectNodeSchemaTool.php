<?php

namespace App\Ai\Tools;

use App\Models\Node;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Returns the config_schema and input_schema for a specific node type.
 */
class InspectNodeSchemaTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the configuration schema and input schema for a specific node type. This tells you what parameters the node accepts.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $nodeType = $request->get('node_type');

        $node = Node::query()->where('type', $nodeType)->first();

        if ($node === null) {
            return json_encode(['error' => "Node type '{$nodeType}' not found"], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'type' => $node->type,
            'name' => $node->name,
            'credential_type' => $node->credential_type,
            'config_schema' => $node->config_schema,
            'input_schema' => $node->input_schema,
            'output_schema' => $node->output_schema,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'node_type' => $schema->string()->required(),
        ];
    }
}

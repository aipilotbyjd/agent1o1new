<?php

namespace App\Ai\Tools;

use App\Models\Node;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lists all available node types for the workflow builder agent.
 */
class ListAvailableNodesTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'List all available workflow node types with their names, descriptions, and categories. Use this to discover what tools are available when building a workflow.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $category = $request->get('category');

        $query = Node::query()->select(['type', 'name', 'description', 'category', 'node_kind']);

        if ($category) {
            $query->where('category', $category);
        }

        $nodes = $query->get()->toArray();

        return json_encode($nodes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string(),
        ];
    }
}

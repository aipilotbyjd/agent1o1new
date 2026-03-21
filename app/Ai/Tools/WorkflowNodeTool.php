<?php

namespace App\Ai\Tools;

use App\Engine\NodeRegistry;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Wraps any engine node as a Laravel AI SDK Tool so the AI Agent can call it.
 */
class WorkflowNodeTool implements Tool
{
    /**
     * @param  array<string, mixed>  $inputSchema  The node's input parameter schema
     * @param  array<string, mixed>  $credentials  Resolved workspace credentials for this node
     */
    public function __construct(
        private string $nodeType,
        private string $toolName,
        private string $toolDescription,
        private array $inputSchema = [],
        private array $credentials = [],
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->toolDescription;
    }

    /**
     * Execute the tool by routing to the engine's NodeRegistry.
     */
    public function handle(Request $request): Stringable|string
    {
        $handlerClass = NodeRegistry::resolve($this->nodeType);

        if ($handlerClass === null) {
            return json_encode(['error' => "Unknown node type: {$this->nodeType}"], JSON_THROW_ON_ERROR);
        }

        $handler = new $handlerClass;

        $payload = new NodePayload(
            nodeId: 'ai-agent-tool-' . uniqid(),
            nodeType: $this->nodeType,
            nodeName: $this->toolName,
            config: array_merge(
                ['operation' => $this->resolveOperation()],
                $request->all(),
            ),
            inputData: $request->all(),
            credentials: $this->credentials,
        );

        $result = $handler->handle($payload);

        if ($result->status === \App\Enums\ExecutionNodeStatus::Failed) {
            return json_encode([
                'error' => $result->error['message'] ?? 'Tool execution failed',
                'code' => $result->error['code'] ?? 'TOOL_ERROR',
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode($result->output, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->convertToSdkSchema($schema, $this->inputSchema);
    }

    /**
     * Extract the operation part from the node type (e.g., 'slack.send_message' → 'send_message').
     */
    private function resolveOperation(): string
    {
        $parts = explode('.', $this->nodeType, 2);

        return $parts[1] ?? $parts[0];
    }

    /**
     * Convert a JSON Schema definition to the Laravel AI SDK JsonSchema format.
     *
     * @param  array<string, mixed>  $jsonSchema
     * @return array<string, mixed>
     */
    private function convertToSdkSchema(JsonSchema $schema, array $jsonSchema): array
    {
        $properties = $jsonSchema['properties'] ?? [];
        $required = $jsonSchema['required'] ?? [];
        $result = [];

        foreach ($properties as $name => $property) {
            $type = $property['type'] ?? 'string';
            $isRequired = in_array($name, $required, true);

            $field = match ($type) {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                'array' => $schema->array()->items($schema->string()),
                'object' => $schema->object(),
                default => $schema->string(),
            };

            if ($isRequired) {
                $field = $field->required();
            }

            $result[$name] = $field;
        }

        return $result;
    }
}

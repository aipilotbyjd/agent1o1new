<?php

namespace App\Engine\Runners;

class NodePayload
{
    /**
     * @param  array<string, mixed>  $config  Node-specific configuration from the workflow definition.
     * @param  array<string, mixed>  $inputData  Resolved upstream data (expressions already evaluated).
     * @param  array<string, mixed>|null  $credentials  Decrypted credential data if the node requires one.
     * @param  array<string, mixed>  $variables  Workspace and runtime variables.
     * @param  array<string, mixed>  $executionMeta  Execution context: execution_id, workspace_id, etc.
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $nodeType,
        public readonly string $nodeName,
        public readonly array $config,
        public readonly array $inputData,
        public readonly ?array $credentials = null,
        public readonly array $variables = [],
        public readonly array $executionMeta = [],
        public readonly ?string $nodeRunKey = null,
    ) {}
}

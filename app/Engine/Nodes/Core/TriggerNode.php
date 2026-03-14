<?php

namespace App\Engine\Nodes\Core;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Entry point for every workflow execution.
 * Passes through trigger_data as its output, making it available to downstream nodes.
 */
class TriggerNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $triggerData = $payload->executionMeta['trigger_data'] ?? [];
        $config = $payload->config;

        $output = [
            'trigger_type' => $config['trigger_type'] ?? 'manual',
            'data' => $triggerData,
            'timestamp' => now()->toIso8601String(),
        ];

        return NodeResult::completed($output);
    }
}

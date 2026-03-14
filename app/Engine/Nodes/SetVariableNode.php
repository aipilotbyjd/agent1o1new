<?php

namespace App\Engine\Nodes;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Sets one or more variables into the execution context.
 */
class SetVariableNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        $assignments = $payload->config['assignments'] ?? [];
        $output = [];

        foreach ($assignments as $key => $value) {
            if (is_string($value)) {
                $output[$key] = data_get($payload->inputData, $value, $value);
            } else {
                $output[$key] = $value;
            }
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return NodeResult::completed($output, $durationMs);
    }
}

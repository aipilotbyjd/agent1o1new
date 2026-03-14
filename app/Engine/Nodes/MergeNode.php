<?php

namespace App\Engine\Nodes;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Merges data from multiple upstream branches into a single output.
 */
class MergeNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        $mode = $payload->config['mode'] ?? 'append';

        $output = match ($mode) {
            'combine' => array_merge_recursive($payload->inputData),
            default => $payload->inputData,
        };

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return NodeResult::completed($output, $durationMs);
    }
}

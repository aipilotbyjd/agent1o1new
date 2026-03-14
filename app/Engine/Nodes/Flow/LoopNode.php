<?php

namespace App\Engine\Nodes\Flow;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionNodeStatus;

/**
 * Iterates over a collection and emits loop items for downstream processing.
 */
class LoopNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        $sourceField = $payload->config['source'] ?? 'items';
        $items = data_get($payload->inputData, $sourceField, []);

        if (! is_array($items)) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed(
                "Loop source '{$sourceField}' is not an array.",
                'LOOP_INVALID_SOURCE',
                $durationMs,
            );
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new NodeResult(
            status: ExecutionNodeStatus::Completed,
            output: ['item_count' => count($items)],
            durationMs: $durationMs,
            loopItems: array_values($items),
        );
    }
}

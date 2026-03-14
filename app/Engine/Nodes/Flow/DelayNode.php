<?php

namespace App\Engine\Nodes\Flow;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Pauses execution for a configured duration before continuing.
 */
class DelayNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        $delaySeconds = (int) ($payload->config['delay_seconds'] ?? $payload->config['seconds'] ?? 0);

        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return NodeResult::completed([
            'delayed_seconds' => $delaySeconds,
            'resumed_at' => now()->toIso8601String(),
        ], $durationMs);
    }
}

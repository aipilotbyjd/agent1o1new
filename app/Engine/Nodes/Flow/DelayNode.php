<?php

namespace App\Engine\Nodes\Flow;

use App\Engine\Contracts\NodeHandler;
use App\Engine\Contracts\SuspendsExecution;
use App\Engine\Data\Suspension;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Pauses execution for a configured duration before continuing.
 *
 * Instead of blocking with sleep(), implements SuspendsExecution to
 * checkpoint state and resume via a delayed queue job.
 */
class DelayNode implements NodeHandler, SuspendsExecution
{
    public function handle(NodePayload $payload): NodeResult
    {
        $delaySeconds = (int) ($payload->config['delay_seconds'] ?? $payload->config['seconds'] ?? 0);

        return NodeResult::completed([
            'delayed_seconds' => $delaySeconds,
            'scheduled_at' => now()->toIso8601String(),
        ]);
    }

    public function suspend(NodePayload $payload): Suspension
    {
        $delaySeconds = (int) ($payload->config['delay_seconds'] ?? $payload->config['seconds'] ?? 0);

        return new Suspension(
            reason: 'delay',
            resumeAt: now()->addSeconds(max($delaySeconds, 0)),
            nodeOutput: [
                'delayed_seconds' => $delaySeconds,
                'scheduled_at' => now()->toIso8601String(),
            ],
        );
    }
}

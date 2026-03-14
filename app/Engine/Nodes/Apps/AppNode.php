<?php

namespace App\Engine\Nodes\Apps;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Nodes\Concerns\ResolvesCredentials;
use App\Engine\Runners\NodePayload;

/**
 * Base class for all third-party app node handlers.
 *
 * Handles boilerplate: timing, try/catch, operation routing, credentials.
 * Each app node only defines its operations and error code.
 */
abstract class AppNode implements NodeHandler
{
    use ResolvesCredentials;

    /**
     * Error code prefix for this app (e.g., "GOOGLE_SHEETS_ERROR").
     */
    abstract protected function errorCode(): string;

    /**
     * Map of operation name → callable.
     *
     * @return array<string, callable(NodePayload): array<string, mixed>>
     */
    abstract protected function operations(): array;

    /**
     * Default operation when none is specified in config.
     */
    protected function defaultOperation(): string
    {
        return array_key_first($this->operations()) ?? '';
    }

    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        try {
            $operation = $payload->config['operation'] ?? $this->defaultOperation();
            $operations = $this->operations();

            if (! isset($operations[$operation])) {
                throw new \InvalidArgumentException("Unknown operation: {$operation}");
            }

            $result = $operations[$operation]($payload);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::completed($result, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed($e->getMessage(), $this->errorCode(), $durationMs);
        }
    }
}

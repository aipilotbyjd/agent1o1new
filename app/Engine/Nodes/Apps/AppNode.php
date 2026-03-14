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
        $maxRetries = (int) ($payload->config['maxRetries'] ?? 3);
        $attempt = 0;

        $operation = $payload->config['operation'] ?? $this->defaultOperation();
        $operations = $this->operations();

        if (! isset($operations[$operation])) {
            return NodeResult::failed("Unknown operation: {$operation}", $this->errorCode());
        }

        while (true) {
            $attempt++;
            try {
                $result = $operations[$operation]($payload);
                $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                return NodeResult::completed($result, $durationMs);
            } catch (\Throwable $e) {
                if ($this->shouldRetry($e, $attempt, $maxRetries)) {
                    $this->sleepForBackoff($attempt);

                    continue;
                }

                $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                return NodeResult::failed($e->getMessage(), $this->errorCode(), $durationMs);
            }
        }
    }

    /**
     * Determine if the exception warrants a retry.
     */
    protected function shouldRetry(\Throwable $e, int $attempt, int $maxRetries): bool
    {
        if ($attempt > $maxRetries) {
            return false;
        }

        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            $status = $e->response->status();

            return in_array($status, [408, 429, 500, 502, 503, 504], true);
        }

        $code = (int) $e->getCode();

        return in_array($code, [408, 429, 500, 502, 503, 504], true);
    }

    /**
     * Sleep for exponential backoff with jitter.
     */
    protected function sleepForBackoff(int $attempt): void
    {
        $baseDelayMs = 1000;
        $delayMs = $baseDelayMs * (2 ** ($attempt - 1));

        $jitter = random_int((int) ($delayMs * -0.2), (int) ($delayMs * 0.2));
        $finalDelayMs = $delayMs + $jitter;

        usleep($finalDelayMs * 1000);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class EngineHealthService
{
    /**
     * Get detailed engine health status.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $baseUrl = $this->getBaseUrl();

        try {
            $response = Http::timeout(5)
                ->get("{$baseUrl}/health/details");

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'status' => 'unhealthy',
                'error' => "Engine returned HTTP {$response->status()}",
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unreachable',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if the engine is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $health = $this->check();

            return ($health['status'] ?? '') === 'healthy';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Basic readiness check.
     */
    public function isReady(): bool
    {
        try {
            $response = Http::timeout(3)
                ->get("{$this->getBaseUrl()}/ready");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get per-partition queue depth stats.
     *
     * @return array<string, mixed>
     */
    public function partitionStats(): array
    {
        try {
            $response = Http::timeout(5)
                ->get("{$this->getBaseUrl()}/health/partitions");

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get DLQ stats from the engine.
     *
     * @return array<string, mixed>
     */
    public function dlqStats(): array
    {
        try {
            $response = Http::timeout(5)
                ->get("{$this->getBaseUrl()}/health/dlq");

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pause a running execution via engine control API.
     */
    public function pauseExecution(int $workspaceId, int $executionId): bool
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->getBaseUrl()}/api/v1/workspaces/workspace-{$workspaceId}/executions/workflow-{$executionId}/pause");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resume a paused execution via engine control API.
     */
    public function resumeExecution(int $workspaceId, int $executionId): bool
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->getBaseUrl()}/api/v1/workspaces/workspace-{$workspaceId}/executions/workflow-{$executionId}/resume");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List DLQ entries from the engine.
     *
     * @return array<string, mixed>
     */
    public function listDlqEntries(int $count = 50): array
    {
        try {
            $response = Http::timeout(10)
                ->get("{$this->getBaseUrl()}/api/v1/dlq", ['count' => $count]);

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Replay a DLQ entry by message ID.
     *
     * @return array<string, mixed>
     */
    public function replayDlqEntry(string $messageId): array
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->getBaseUrl()}/api/v1/dlq/{$messageId}/replay");

            return $response->successful()
                ? $response->json()
                : ['error' => "Engine returned HTTP {$response->status()}"];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Invalidate a cached workflow in the engine.
     */
    public function invalidateWorkflowCache(string $versionHash): bool
    {
        try {
            $response = Http::timeout(5)
                ->post("{$this->getBaseUrl()}/api/v1/cache/invalidate", [
                    'version_hash' => $versionHash,
                ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get engine cache statistics.
     *
     * @return array<string, mixed>
     */
    public function cacheStats(): array
    {
        try {
            $response = Http::timeout(5)
                ->get("{$this->getBaseUrl()}/api/v1/cache/stats");

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function getBaseUrl(): string
    {
        return config('services.engine.engine_http_url', 'http://localhost:8080');
    }
}

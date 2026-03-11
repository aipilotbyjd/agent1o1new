<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EngineHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoints for monitoring and managing the Go Execution Engine.
 * Provides visibility into engine health, DLQ, partition depth, and cache.
 */
class EngineDashboardController extends Controller
{
    public function __construct(private EngineHealthService $engineHealth) {}

    /**
     * Get comprehensive engine health status.
     * GET /api/v1/workspaces/{workspace}/engine/health
     */
    public function health(): JsonResponse
    {
        $health = $this->engineHealth->check();

        return $this->successResponse('Engine health retrieved.', $health);
    }

    /**
     * Get per-partition queue depth stats.
     * GET /api/v1/workspaces/{workspace}/engine/partitions
     */
    public function partitions(): JsonResponse
    {
        $stats = $this->engineHealth->partitionStats();

        return $this->successResponse('Partition stats retrieved.', $stats);
    }

    /**
     * List Dead Letter Queue entries.
     * GET /api/v1/workspaces/{workspace}/engine/dlq
     */
    public function dlq(Request $request): JsonResponse
    {
        $count = $request->integer('count', 50);
        $entries = $this->engineHealth->listDlqEntries($count);

        return $this->successResponse('DLQ entries retrieved.', $entries);
    }

    /**
     * Replay a DLQ entry back to its original partition.
     * POST /api/v1/workspaces/{workspace}/engine/dlq/{messageId}/replay
     */
    public function dlqReplay(string $messageId): JsonResponse
    {
        $result = $this->engineHealth->replayDlqEntry($messageId);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], 500);
        }

        return $this->successResponse('DLQ entry replayed.', $result);
    }

    /**
     * Get engine workflow cache statistics.
     * GET /api/v1/workspaces/{workspace}/engine/cache
     */
    public function cache(): JsonResponse
    {
        $stats = $this->engineHealth->cacheStats();

        return $this->successResponse('Cache stats retrieved.', $stats);
    }

    /**
     * Invalidate a cached workflow in the engine.
     * POST /api/v1/workspaces/{workspace}/engine/cache/invalidate
     */
    public function cacheInvalidate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version_hash' => ['required', 'string'],
        ]);

        $success = $this->engineHealth->invalidateWorkflowCache($validated['version_hash']);

        if (! $success) {
            return $this->errorResponse('Failed to invalidate cache.', 500);
        }

        return $this->successResponse('Cache invalidated.');
    }

    /**
     * Pause a running execution via engine control.
     * POST /api/v1/workspaces/{workspace}/executions/{execution}/pause-engine
     */
    public function pauseExecution(Request $request, int $workspace, int $execution): JsonResponse
    {
        $success = $this->engineHealth->pauseExecution($workspace, $execution);

        if (! $success) {
            return $this->errorResponse('Failed to pause execution.', 500);
        }

        return $this->successResponse('Pause signal sent to engine.');
    }

    /**
     * Resume a paused execution via engine control.
     * POST /api/v1/workspaces/{workspace}/executions/{execution}/resume-engine
     */
    public function resumeExecution(Request $request, int $workspace, int $execution): JsonResponse
    {
        $success = $this->engineHealth->resumeExecution($workspace, $execution);

        if (! $success) {
            return $this->errorResponse('Failed to resume execution.', 500);
        }

        return $this->successResponse('Resume signal sent to engine.');
    }
}

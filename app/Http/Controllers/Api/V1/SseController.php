<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    /**
     * Stream execution events via SSE.
     * Reads from Redis Streams for reliable catch-up + Redis Pub/Sub for instant push.
     *
     * GET /api/v1/workspaces/{workspace}/executions/{execution}/stream
     */
    public function stream(Request $request, Workspace $workspace, Execution $execution): StreamedResponse
    {
        $this->can(Permission::ExecutionView);

        $streamKey = "execution:{$execution->id}:events";
        $pubsubChannel = "linkflow:execution:{$execution->id}:live";
        $lastId = $request->header('Last-Event-ID', '0-0');

        return new StreamedResponse(function () use ($streamKey, $lastId) {
            // Disable output buffering
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('X-Accel-Buffering: no');

            $currentId = $lastId;
            $heartbeatInterval = 15;
            $lastHeartbeat = time();
            $maxDuration = 300;
            $startTime = time();

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                if ((time() - $startTime) >= $maxDuration) {
                    echo "event: timeout\ndata: {\"message\":\"Stream timeout, please reconnect.\"}\n\n";
                    flush();
                    break;
                }

                // Try Redis Streams first (reliable, ordered)
                try {
                    $messages = Redis::connection()->client()->xread(
                        [$streamKey => $currentId],
                        1,
                        2000,
                    );
                } catch (\Throwable) {
                    usleep(500000);

                    continue;
                }

                if ($messages && isset($messages[$streamKey])) {
                    foreach ($messages[$streamKey] as $id => $fields) {
                        $currentId = $id;
                        $payload = $fields['payload'] ?? '{}';
                        $decoded = json_decode($payload, true);
                        $event = $decoded['event'] ?? 'message';

                        echo "id: {$id}\n";
                        echo "event: {$event}\n";
                        echo "data: {$payload}\n\n";
                        flush();

                        if (in_array($event, ['execution.completed', 'execution.failed', 'execution.cancelled'])) {
                            break 2;
                        }
                    }
                }

                if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = time();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Stream all execution events for an entire workspace via SSE.
     * Useful for dashboard-level monitoring of all running workflows.
     *
     * GET /api/v1/workspaces/{workspace}/executions/stream-all
     */
    public function streamWorkspace(Request $request, Workspace $workspace): StreamedResponse
    {
        $this->can(Permission::ExecutionView);

        $pubsubChannel = "linkflow:workspace:{$workspace->id}:events";

        return new StreamedResponse(function () use ($workspace) {
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('X-Accel-Buffering: no');

            $maxDuration = 300;
            $startTime = time();
            $heartbeatInterval = 15;
            $lastHeartbeat = time();

            // Poll active executions' streams
            $activeExecutions = $workspace->executions()
                ->active()
                ->pluck('id')
                ->toArray();

            $streamKeys = [];
            $lastIds = [];
            foreach ($activeExecutions as $execId) {
                $key = "execution:{$execId}:events";
                $streamKeys[$key] = '0-0';
                $lastIds[$key] = '0-0';
            }

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                if ((time() - $startTime) >= $maxDuration) {
                    echo "event: timeout\ndata: {\"message\":\"Stream timeout, please reconnect.\"}\n\n";
                    flush();
                    break;
                }

                if (! empty($streamKeys)) {
                    try {
                        $messages = Redis::connection()->client()->xread(
                            $lastIds,
                            5,
                            2000,
                        );
                    } catch (\Throwable) {
                        usleep(500000);

                        continue;
                    }

                    if ($messages) {
                        foreach ($messages as $streamKey => $entries) {
                            foreach ($entries as $id => $fields) {
                                $lastIds[$streamKey] = $id;
                                $payload = $fields['payload'] ?? '{}';
                                $decoded = json_decode($payload, true);
                                $event = $decoded['event'] ?? 'message';

                                echo "id: {$id}\n";
                                echo "event: {$event}\n";
                                echo "data: {$payload}\n\n";
                                flush();
                            }
                        }
                    }
                } else {
                    usleep(2000000); // No active executions, wait 2 seconds
                }

                if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                    // Refresh active executions list periodically
                    $activeExecutions = $workspace->executions()
                        ->active()
                        ->pluck('id')
                        ->toArray();

                    foreach ($activeExecutions as $execId) {
                        $key = "execution:{$execId}:events";
                        if (! isset($lastIds[$key])) {
                            $lastIds[$key] = '0-0';
                        }
                    }

                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = time();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }
}

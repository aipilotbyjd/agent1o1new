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
     */
    public function stream(Request $request, Workspace $workspace, Execution $execution): StreamedResponse
    {
        $this->can(Permission::ExecutionView);

        $streamKey = "execution:{$execution->id}:events";
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
}

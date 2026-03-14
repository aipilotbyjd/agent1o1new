<?php

namespace App\Engine\Nodes;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

/**
 * Executes an outbound HTTP request.
 */
class HttpRequestNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        try {
            $config = $payload->config;
            $method = strtolower($config['method'] ?? 'get');
            $url = $config['url'] ?? '';
            $headers = $config['headers'] ?? [];
            $body = $config['body'] ?? null;
            $timeout = (int) ($config['timeout'] ?? 30);

            $request = Http::timeout($timeout)->withHeaders($headers);

            if ($config['auth']['type'] ?? false) {
                $request = $this->applyAuth($request, $config['auth'], $payload->credentials);
            }

            $response = match ($method) {
                'post' => $request->post($url, $body),
                'put' => $request->put($url, $body),
                'patch' => $request->patch($url, $body),
                'delete' => $request->delete($url, $body),
                default => $request->get($url, $body),
            };

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $output = [
                'status_code' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->json() ?? $response->body(),
                'ok' => $response->successful(),
            ];

            if ($response->failed() && ($config['fail_on_error'] ?? true)) {
                return NodeResult::failed(
                    "HTTP {$response->status()}: {$response->body()}",
                    'HTTP_ERROR',
                    $durationMs,
                );
            }

            return NodeResult::completed($output, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed($e->getMessage(), 'HTTP_REQUEST_ERROR', $durationMs);
        }
    }

    /**
     * @param  array<string, mixed>  $auth
     * @param  array<string, mixed>|null  $credentials
     */
    private function applyAuth(
        \Illuminate\Http\Client\PendingRequest $request,
        array $auth,
        ?array $credentials,
    ): \Illuminate\Http\Client\PendingRequest {
        return match ($auth['type'] ?? null) {
            'bearer' => $request->withToken($credentials['token'] ?? $auth['token'] ?? ''),
            'basic' => $request->withBasicAuth(
                $credentials['username'] ?? $auth['username'] ?? '',
                $credentials['password'] ?? $auth['password'] ?? '',
            ),
            'header' => $request->withHeaders([
                $auth['header_name'] ?? 'Authorization' => $credentials['value'] ?? $auth['value'] ?? '',
            ]),
            default => $request,
        };
    }
}

<?php

namespace App\Engine\Nodes\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Shared credential handling for app node handlers.
 */
trait ResolvesCredentials
{
    /**
     * Build an authenticated HTTP client from the node's credentials.
     *
     * @param  array<string, mixed>|null  $credentials
     * @param  array<string, mixed>  $headers
     */
    protected function authenticatedRequest(?array $credentials, array $headers = [], int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout($timeout)->withHeaders($headers);

        if (! $credentials) {
            return $request;
        }

        $authType = $credentials['auth_type'] ?? $credentials['type'] ?? null;

        return match ($authType) {
            'bearer', 'oauth2' => $request->withToken($credentials['access_token'] ?? $credentials['token'] ?? ''),
            'basic' => $request->withBasicAuth(
                $credentials['username'] ?? '',
                $credentials['password'] ?? '',
            ),
            'api_key' => $request->withHeaders([
                $credentials['header_name'] ?? 'Authorization' => $credentials['api_key'] ?? $credentials['value'] ?? '',
            ]),
            'service_account' => $request->withToken($this->resolveServiceAccountToken($credentials)),
            default => $request,
        };
    }

    /**
     * Resolve a Google service account JSON into a short-lived access token.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function resolveServiceAccountToken(array $credentials): string
    {
        return $credentials['access_token'] ?? '';
    }
}

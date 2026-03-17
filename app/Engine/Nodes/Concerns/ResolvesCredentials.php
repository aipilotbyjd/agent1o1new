<?php

namespace App\Engine\Nodes\Concerns;

use Illuminate\Support\Facades\Cache;
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

        if ($authType === null && ! empty($credentials['access_token'])) {
            $authType = 'bearer';
        }

        return match (strtolower((string) $authType)) {
            'bearer', 'oauth2' => $request->withToken($credentials['access_token'] ?? $credentials['token'] ?? ''),
            'basic' => $request->withBasicAuth(
                $credentials['username'] ?? '',
                $credentials['password'] ?? '',
            ),
            'api_key' => $request->withHeaders([
                $credentials['header_name'] ?? 'Authorization' => $credentials['api_key'] ?? $credentials['value'] ?? '',
            ]),
            'service_account' => $request->withToken($this->resolveServiceAccountToken($credentials, $credentials['scopes'] ?? [])),
            default => $request,
        };
    }

    /**
     * Resolve a Google service account JSON into a short-lived access token.
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $scopes
     */
    protected function resolveServiceAccountToken(array $credentials, array $scopes = []): string
    {
        $clientEmail = $credentials['client_email'] ?? '';
        $privateKey = $credentials['private_key'] ?? '';
        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $scopeString = implode(' ', $scopes);

        $cacheKey = 'google_sa_token:'.md5($clientEmail.$scopeString);

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => $scopeString,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $unsignedJwt = $header.'.'.$payload;
        $signature = '';
        openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $jwt = $unsignedJwt.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $response->throw();

        $accessToken = $response->json('access_token', '');
        $expiresIn = $response->json('expires_in', 3600);

        Cache::put($cacheKey, $accessToken, max($expiresIn - 300, 60));

        return $accessToken;
    }

    /**
     * Base64 URL-safe encode without padding.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

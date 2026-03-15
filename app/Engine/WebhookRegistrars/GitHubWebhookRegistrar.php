<?php

namespace App\Engine\WebhookRegistrars;

use App\Engine\Contracts\WebhookRegistrar;
use Illuminate\Support\Facades\Http;

class GitHubWebhookRegistrar implements WebhookRegistrar
{
    private const BASE_URL = 'https://api.github.com';

    /**
     * The provider name identifier.
     */
    public function provider(): string
    {
        return 'github';
    }

    /**
     * Check if the webhook still exists on the external service.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $providerConfig
     */
    public function checkExists(string $externalId, array $credentials, array $providerConfig = []): bool
    {
        $response = $this->client($credentials)
            ->get($this->repoUrl($providerConfig)."/hooks/{$externalId}");

        return $response->status() === 200;
    }

    /**
     * Register a webhook with the external service.
     *
     * Handles 422 errors by searching for an existing webhook with the same callback URL.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $providerConfig
     * @return array{external_id: string, secret: string}
     */
    public function register(string $callbackUrl, array $events, array $credentials, array $providerConfig = []): array
    {
        $secret = bin2hex(random_bytes(32));

        $response = $this->client($credentials)
            ->post($this->repoUrl($providerConfig).'/hooks', [
                'name' => 'web',
                'config' => [
                    'url' => $callbackUrl,
                    'content_type' => 'json',
                    'insecure_ssl' => '0',
                    'secret' => $secret,
                ],
                'events' => $events,
                'active' => true,
            ]);

        if ($response->status() === 422) {
            return $this->findExistingWebhook($callbackUrl, $secret, $credentials, $providerConfig);
        }

        $response->throw();

        return [
            'external_id' => (string) $response->json('id'),
            'secret' => $secret,
        ];
    }

    /**
     * Unregister (delete) a webhook from the external service.
     *
     * Silently ignores 404 errors when the webhook has already been removed.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $providerConfig
     */
    public function unregister(string $externalId, array $credentials, array $providerConfig = []): void
    {
        $response = $this->client($credentials)
            ->delete($this->repoUrl($providerConfig)."/hooks/{$externalId}");

        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    /**
     * Verify the signature of an incoming webhook payload.
     *
     * GitHub sends the `X-Hub-Signature-256` header as `sha256=<hash>`.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $computed = hash_hmac('sha256', $payload, $secret);

        return hash_equals('sha256='.$computed, $signature);
    }

    /**
     * Build an authenticated HTTP client for the GitHub API.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function client(array $credentials): \Illuminate\Http\Client\PendingRequest
    {
        $token = $credentials['access_token'] ?? $credentials['token'];

        return Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->accept('application/vnd.github+json');
    }

    /**
     * Build the repository base URL segment.
     *
     * @param  array<string, mixed>  $providerConfig
     */
    private function repoUrl(array $providerConfig): string
    {
        return "/repos/{$providerConfig['owner']}/{$providerConfig['repository']}";
    }

    /**
     * Find an existing webhook that matches the given callback URL.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $providerConfig
     * @return array{external_id: string, secret: string}
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    private function findExistingWebhook(string $callbackUrl, string $secret, array $credentials, array $providerConfig): array
    {
        $response = $this->client($credentials)
            ->get($this->repoUrl($providerConfig).'/hooks');

        $response->throw();

        $hooks = $response->json();

        foreach ($hooks as $hook) {
            if (($hook['config']['url'] ?? null) === $callbackUrl) {
                return [
                    'external_id' => (string) $hook['id'],
                    'secret' => $secret,
                ];
            }
        }

        throw new \RuntimeException("Webhook already exists but could not be found for URL: {$callbackUrl}");
    }
}

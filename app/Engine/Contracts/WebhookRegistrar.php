<?php

namespace App\Engine\Contracts;

interface WebhookRegistrar
{
    /**
     * Check if the webhook still exists on the external service.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credential data
     * @param  array<string, mixed>  $providerConfig  Provider-specific config (repo, events, etc.)
     */
    public function checkExists(string $externalId, array $credentials, array $providerConfig = []): bool;

    /**
     * Register a webhook with the external service.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credential data
     * @param  array<string, mixed>  $providerConfig  Provider-specific config
     * @return array{external_id: string, secret: string}
     */
    public function register(string $callbackUrl, array $events, array $credentials, array $providerConfig = []): array;

    /**
     * Unregister (delete) a webhook from the external service.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credential data
     * @param  array<string, mixed>  $providerConfig  Provider-specific config
     */
    public function unregister(string $externalId, array $credentials, array $providerConfig = []): void;

    /**
     * Verify the signature of an incoming webhook payload.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool;

    /**
     * The provider name identifier (e.g., 'stripe', 'github').
     */
    public function provider(): string;
}

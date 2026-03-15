<?php

namespace App\Engine\WebhookRegistrars;

use App\Engine\Contracts\WebhookRegistrar;

/**
 * Maps trigger node types to their webhook registrar implementation.
 *
 * Convention: trigger nodes with provider-specific webhooks declare a
 * `trigger_type` like "stripe" or "github" in their config. This registry
 * resolves that to the appropriate WebhookRegistrar.
 */
class WebhookRegistrarRegistry
{
    /** @var array<string, class-string<WebhookRegistrar>> */
    private const REGISTRARS = [
        'stripe' => StripeWebhookRegistrar::class,
        'github' => GitHubWebhookRegistrar::class,
    ];

    /**
     * Resolve a registrar by provider name.
     */
    public static function resolve(string $provider): ?WebhookRegistrar
    {
        $class = self::REGISTRARS[$provider] ?? null;

        if ($class === null) {
            return null;
        }

        return app($class);
    }

    /**
     * Check if a provider has a webhook registrar.
     */
    public static function supports(string $provider): bool
    {
        return isset(self::REGISTRARS[$provider]);
    }

    /**
     * Get all supported provider names.
     *
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(self::REGISTRARS);
    }
}

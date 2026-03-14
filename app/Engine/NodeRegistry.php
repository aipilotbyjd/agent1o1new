<?php

namespace App\Engine;

use App\Engine\Contracts\NodeHandler;
use App\Engine\Enums\NodeType;
use Illuminate\Support\Str;

/**
 * Resolves a node type string to its handler class.
 *
 * Resolution order:
 *  1. Core/Flow nodes via the NodeType enum (fast, ~13 stable cases)
 *  2. App nodes via naming convention (zero-config, unlimited scale)
 *
 * Convention for app nodes:
 *   "google_sheets.append_row" → App\Engine\Nodes\Apps\Google\GoogleSheetsNode
 *   "slack.send_message"       → App\Engine\Nodes\Apps\Slack\SlackNode
 *   "stripe.create_invoice"    → App\Engine\Nodes\Apps\Stripe\StripeNode
 *
 * The operation (after the dot) is passed via $payload->config['operation']
 * and handled inside the node class via a match() statement.
 */
class NodeRegistry
{
    /** @var array<string, class-string<NodeHandler>> */
    private static array $cache = [];

    /**
     * Resolve a node type string to its handler class.
     *
     * @return class-string<NodeHandler>|null
     */
    public static function resolve(string $type): ?string
    {
        if (isset(self::$cache[$type])) {
            return self::$cache[$type];
        }

        // 1. Try core/flow enum first
        $enumCase = NodeType::tryFrom($type);
        if ($enumCase !== null) {
            return self::$cache[$type] = $enumCase->handlerClass();
        }

        // 2. Try convention-based app resolution
        $handlerClass = self::resolveAppHandler($type);
        if ($handlerClass !== null && class_exists($handlerClass)) {
            return self::$cache[$type] = $handlerClass;
        }

        return null;
    }

    /**
     * Resolve a handler instance from the container.
     */
    public static function handler(string $type): ?NodeHandler
    {
        $class = self::resolve($type);

        if ($class === null) {
            return null;
        }

        return app($class);
    }

    /**
     * Extract the operation name from a dotted type string.
     *
     * "google_sheets.append_row" → "append_row"
     * "trigger" → null
     */
    public static function operation(string $type): ?string
    {
        if (! str_contains($type, '.')) {
            return null;
        }

        return Str::afterLast($type, '.');
    }

    /**
     * Check if a node type is an app node (has a dot separator).
     */
    public static function isAppNode(string $type): bool
    {
        return str_contains($type, '.') && ! NodeType::tryFrom($type);
    }

    /**
     * Convention-based resolution for app node types.
     *
     * Pattern: "{app_slug}.{operation}" → App\Engine\Nodes\Apps\{AppDir}\{AppName}Node
     *
     * Examples:
     *   google_sheets  → Apps\Google\GoogleSheetsNode
     *   gmail          → Apps\Google\GmailNode
     *   slack          → Apps\Slack\SlackNode
     *   stripe         → Apps\Stripe\StripeNode
     *
     * @return class-string<NodeHandler>|null
     */
    private static function resolveAppHandler(string $type): ?string
    {
        if (! str_contains($type, '.')) {
            return null;
        }

        $appSlug = Str::beforeLast($type, '.');

        // Check the explicit directory mapping first (for grouped apps like Google)
        $mapping = self::appDirectoryMap();

        if (isset($mapping[$appSlug])) {
            [$directory, $className] = $mapping[$appSlug];

            return "App\\Engine\\Nodes\\Apps\\{$directory}\\{$className}";
        }

        // Fallback: auto-generate from slug
        // "slack" → Apps\Slack\SlackNode
        $appName = Str::studly($appSlug);
        $directory = $appName;

        return "App\\Engine\\Nodes\\Apps\\{$directory}\\{$appName}Node";
    }

    /**
     * Explicit mapping for apps that share a directory or have non-standard names.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function appDirectoryMap(): array
    {
        return [
            // Google apps share the Google/ directory
            'google_sheets' => ['Google', 'GoogleSheetsNode'],
            'gmail' => ['Google', 'GmailNode'],
            'google_drive' => ['Google', 'GoogleDriveNode'],
            'google_calendar' => ['Google', 'GoogleCalendarNode'],
        ];
    }

    /**
     * Clear the resolution cache (for testing).
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}

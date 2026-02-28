<?php

namespace App\Exceptions;

use App\Http\Response\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base exception for all API-layer errors.
 *
 * Self-renders via Laravel's renderable exception contract — throw anywhere
 * and the framework produces a consistent JSON envelope automatically.
 *
 * @example throw ApiException::forbidden('Only owners can do this.');
 * @example throw ApiException::paymentRequired('Insufficient credits.');
 * @example throw new ApiException('Custom message', 418, ['reason' => 'teapot']);
 *
 * Extend for domain-specific exceptions:
 * @example class InsufficientCreditsException extends ApiException { ... }
 */
class ApiException extends RuntimeException
{
    /**
     * @param  string  $message  Human-readable error description.
     * @param  int  $statusCode  HTTP status code (default 400).
     * @param  mixed  $errors  Optional structured error details (validation bag, domain context).
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
        public readonly mixed $errors = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Self-render into a consistent JSON response for API requests.
     *
     * Returns `false` for non-API requests so Laravel falls back
     * to its default exception rendering (HTML error pages, etc.).
     */
    public function render(Request $request): JsonResponse|false
    {
        if (! $request->is('api/*')) {
            return false;
        }

        $response = ApiResponse::error($this->message);

        if ($this->errors !== null) {
            $response->errors($this->errors);
        }

        return $response->send($this->statusCode);
    }

    // ─── Named Constructors ──────────────────────────────────

    /**
     * HTTP 400 — Bad Request.
     *
     * @param  mixed  $errors  Optional structured error details.
     */
    public static function badRequest(string $message, mixed $errors = null): static
    {
        return new static($message, 400, $errors);
    }

    /**
     * HTTP 401 — Unauthenticated (missing or invalid credentials).
     */
    public static function unauthorized(string $message = 'Unauthenticated.'): static
    {
        return new static($message, 401);
    }

    /**
     * HTTP 402 — Payment Required (insufficient credits, expired plan).
     */
    public static function paymentRequired(string $message): static
    {
        return new static($message, 402);
    }

    /**
     * HTTP 403 — Forbidden (authenticated but lacking permission).
     */
    public static function forbidden(string $message = 'Forbidden.'): static
    {
        return new static($message, 403);
    }

    /**
     * HTTP 404 — Resource Not Found.
     */
    public static function notFound(string $message = 'Resource not found.'): static
    {
        return new static($message, 404);
    }

    /**
     * HTTP 409 — Conflict (duplicate entry, state conflict).
     */
    public static function conflict(string $message): static
    {
        return new static($message, 409);
    }

    /**
     * HTTP 422 — Unprocessable Entity (domain validation failure).
     *
     * @param  mixed  $errors  Optional structured error details.
     */
    public static function unprocessable(string $message, mixed $errors = null): static
    {
        return new static($message, 422, $errors);
    }

    /**
     * HTTP 429 — Too Many Requests (rate limit exceeded).
     */
    public static function tooManyRequests(string $message = 'Too many requests.'): static
    {
        return new static($message, 429);
    }

    /**
     * HTTP 500 — Internal Server Error.
     */
    public static function serverError(string $message = 'Internal server error.'): static
    {
        return new static($message, 500);
    }
}

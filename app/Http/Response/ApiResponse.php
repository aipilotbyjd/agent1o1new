<?php

namespace App\Http\Response;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Unified JSON response builder for all API endpoints.
 *
 * Uses a factory → builder → terminal pattern:
 *   ApiResponse::success('Loaded.')->data($resource)->send();
 *   ApiResponse::error('Failed.')->errors($bag)->send(422);
 *
 * @example Success:   {"success":true,"statusCode":200,"message":"...","data":{...}}
 * @example Paginated: {"success":true,"statusCode":200,"message":"...","data":[...],"pagination":{...}}
 * @example Error:     {"success":false,"statusCode":422,"message":"...","errors":{...}}
 */
final class ApiResponse
{
    private bool $success;

    private string $message;

    private int $statusCode;

    /** @var mixed Payload attached via data(). */
    private mixed $data = null;

    /** @var bool Tracks whether data() was called (allows null payloads). */
    private bool $hasData = false;

    /** @var mixed Validation or domain error details. */
    private mixed $errors = null;

    /** @var array{total:int,per_page:int,current_page:int,last_page:int,from:int|null,to:int|null} */
    private array $pagination = [];

    private function __construct(bool $success, string $message, int $statusCode)
    {
        $this->success = $success;
        $this->message = $message;
        $this->statusCode = $statusCode;
    }

    // ─── Factory Methods ─────────────────────────────────────

    /**
     * Begin building a success response (HTTP 200 by default).
     */
    public static function success(string $message = 'Success.'): self
    {
        return new self(true, $message, 200);
    }

    /**
     * Begin building an error response (HTTP 400 by default).
     */
    public static function error(string $message = 'An error occurred.'): self
    {
        return new self(false, $message, 400);
    }

    // ─── Builder Methods (chainable) ─────────────────────────

    /**
     * Attach a data payload (resource, array, scalar, or null).
     *
     * @param  JsonResource|array<string, mixed>|mixed  $data
     */
    public function data(mixed $data): self
    {
        $this->data = $data;
        $this->hasData = true;

        return $this;
    }

    /**
     * Attach error details (validation bag, domain context, etc.).
     *
     * @param  array<string, mixed>|string|mixed  $errors
     */
    public function errors(mixed $errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    /**
     * Override the message set by the factory method.
     */
    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Override the HTTP status code set by the factory method.
     */
    public function status(int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * Extract paginated data and meta from a ResourceCollection.
     *
     * Populates both `data` (items) and `pagination` (meta) keys.
     */
    public function paginate(ResourceCollection $collection): self
    {
        $paginated = $collection->response()->getData(true);

        $this->data = $paginated['data'];
        $this->hasData = true;

        if (isset($paginated['meta'])) {
            $meta = $paginated['meta'];

            $this->pagination = [
                'total' => $meta['total'] ?? 0,
                'per_page' => $meta['per_page'] ?? 15,
                'current_page' => $meta['current_page'] ?? 1,
                'last_page' => $meta['last_page'] ?? 1,
                'from' => $meta['from'] ?? null,
                'to' => $meta['to'] ?? null,
            ];
        }

        return $this;
    }

    // ─── Terminal Method ─────────────────────────────────────

    /**
     * Build and return the JsonResponse.
     *
     * @param  int|null  $statusCode  Optional last-minute status override.
     */
    public function send(?int $statusCode = null): JsonResponse
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }

        $body = [
            'success' => $this->success,
            'statusCode' => $this->statusCode,
            'message' => $this->message,
        ];

        if ($this->hasData) {
            $body['data'] = $this->data;
        }

        if ($this->errors !== null) {
            $body['errors'] = $this->errors;
        }

        if (! empty($this->pagination)) {
            $body['pagination'] = $this->pagination;
        }

        return response()->json($body, $this->statusCode);
    }

    // ─── Success Shortcuts ───────────────────────────────────

    /**
     * Respond with HTTP 201 Created.
     */
    public static function created(string $message, mixed $data = null): JsonResponse
    {
        $response = self::success($message);

        if ($data !== null) {
            $response->data($data);
        }

        return $response->send(201);
    }

    /**
     * Respond with HTTP 202 Accepted (queued / async operations).
     */
    public static function accepted(string $message, mixed $data = null): JsonResponse
    {
        $response = self::success($message);

        if ($data !== null) {
            $response->data($data);
        }

        return $response->send(202);
    }

    // ─── Error Shortcuts ─────────────────────────────────────

    /**
     * Respond with HTTP 401 Unauthenticated.
     */
    public static function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error($message)->send(401);
    }

    /**
     * Respond with HTTP 403 Forbidden.
     */
    public static function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return self::error($message)->send(403);
    }

    /**
     * Respond with HTTP 404 Not Found.
     */
    public static function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return self::error($message)->send(404);
    }

    /**
     * Respond with HTTP 422 Unprocessable Entity (validation errors).
     *
     * @param  array<string, list<string>>  $errors  Keyed validation error bag.
     */
    public static function validationFailed(array $errors, string $message = 'Validation failed.'): JsonResponse
    {
        return self::error($message)->errors($errors)->send(422);
    }

    /**
     * Respond with HTTP 429 Too Many Requests.
     */
    public static function tooManyRequests(string $message = 'Too many requests.'): JsonResponse
    {
        return self::error($message)->send(429);
    }

    /**
     * Respond with HTTP 500 Internal Server Error.
     */
    public static function serverError(string $message = 'Internal server error.'): JsonResponse
    {
        return self::error($message)->send(500);
    }
}

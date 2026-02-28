<?php

namespace App\Traits;

use App\Http\Response\ApiResponse as ResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Convenience trait for controllers that need quick response helpers.
 *
 * Delegates to the ApiResponse builder so all JSON envelopes stay consistent.
 *
 * @example return $this->successResponse('User loaded.', new UserResource($user));
 * @example return $this->paginatedResponse('List.', UserResource::collection($users));
 * @example return $this->errorResponse('Invalid.', 400, ['field' => 'bad']);
 */
trait ApiResponse
{
    /**
     * Return a success JSON response.
     *
     * @param  string  $message  Human-readable success message.
     * @param  array<string, mixed>|JsonResource|null  $data  Payload (resource, array, or null).
     * @param  int  $statusCode  HTTP status (default 200).
     */
    protected function successResponse(string $message, array|JsonResource|null $data = null, int $statusCode = 200): JsonResponse
    {
        $response = ResponseBuilder::success($message);

        if ($data !== null) {
            $response->data($data);
        }

        return $response->send($statusCode);
    }

    /**
     * Return a paginated success JSON response.
     *
     * Automatically extracts items and pagination meta from the collection.
     *
     * @param  string  $message  Human-readable success message.
     * @param  ResourceCollection  $collection  Paginated resource collection.
     */
    protected function paginatedResponse(string $message, ResourceCollection $collection): JsonResponse
    {
        return ResponseBuilder::success($message)
            ->paginate($collection)
            ->send();
    }

    /**
     * Return an error JSON response.
     *
     * @param  string  $message  Human-readable error message.
     * @param  int  $statusCode  HTTP status (default 400).
     * @param  array<string, mixed>|string|null  $errors  Optional error details.
     */
    protected function errorResponse(string $message, int $statusCode = 400, mixed $errors = null): JsonResponse
    {
        $response = ResponseBuilder::error($message);

        if ($errors !== null) {
            $response->errors($errors);
        }

        return $response->send($statusCode);
    }
}

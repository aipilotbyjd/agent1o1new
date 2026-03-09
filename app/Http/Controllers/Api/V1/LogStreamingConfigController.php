<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LogStreamingConfigResource;
use App\Models\LogStreamingConfig;
use App\Models\Workspace;
use App\Services\LogStreamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogStreamingConfigController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private LogStreamingService $logStreamingService) {}

    /**
     * List log streaming configurations.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceView);

        $query = $workspace->logStreamingConfigs()->with('creator');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $configs = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Log streaming configs retrieved successfully.',
            LogStreamingConfigResource::collection($configs),
        );
    }

    /**
     * Create a log streaming configuration.
     */
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceUpdate);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'destination_type' => ['required', 'string', 'in:webhook,s3,datadog,elasticsearch,syslog'],
            'destination_config' => ['required', 'array'],
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['string', 'in:execution.completed,execution.failed,execution.started,execution.cancelled,node.completed,node.failed'],
            'is_active' => ['nullable', 'boolean'],
            'include_node_data' => ['nullable', 'boolean'],
        ]);

        $config = $this->logStreamingService->create(
            $workspace,
            $request->user(),
            $validated,
        );

        $config->load('creator');

        return $this->successResponse(
            'Log streaming config created successfully.',
            new LogStreamingConfigResource($config),
            201,
        );
    }

    /**
     * Show a log streaming configuration.
     */
    public function show(Workspace $workspace, LogStreamingConfig $logStreamingConfig): JsonResponse
    {
        $this->can(Permission::WorkspaceView);

        $logStreamingConfig->load('creator');

        return $this->successResponse(
            'Log streaming config retrieved successfully.',
            new LogStreamingConfigResource($logStreamingConfig),
        );
    }

    /**
     * Update a log streaming configuration.
     */
    public function update(Request $request, Workspace $workspace, LogStreamingConfig $logStreamingConfig): JsonResponse
    {
        $this->can(Permission::WorkspaceUpdate);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'destination_type' => ['nullable', 'string', 'in:webhook,s3,datadog,elasticsearch,syslog'],
            'destination_config' => ['nullable', 'array'],
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['string', 'in:execution.completed,execution.failed,execution.started,execution.cancelled,node.completed,node.failed'],
            'is_active' => ['nullable', 'boolean'],
            'include_node_data' => ['nullable', 'boolean'],
        ]);

        $config = $this->logStreamingService->update($logStreamingConfig, $validated);
        $config->load('creator');

        return $this->successResponse(
            'Log streaming config updated successfully.',
            new LogStreamingConfigResource($config),
        );
    }

    /**
     * Delete a log streaming configuration.
     */
    public function destroy(Workspace $workspace, LogStreamingConfig $logStreamingConfig): JsonResponse
    {
        $this->can(Permission::WorkspaceUpdate);

        $this->logStreamingService->delete($logStreamingConfig);

        return $this->successResponse('Log streaming config deleted successfully.');
    }
}

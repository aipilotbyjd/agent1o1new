<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LogStreaming\StoreLogStreamingConfigRequest;
use App\Http\Requests\Api\V1\LogStreaming\UpdateLogStreamingConfigRequest;
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
    public function store(StoreLogStreamingConfigRequest $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validated();

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
    public function update(UpdateLogStreamingConfigRequest $request, Workspace $workspace, LogStreamingConfig $logStreamingConfig): JsonResponse
    {
        $validated = $request->validated();

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

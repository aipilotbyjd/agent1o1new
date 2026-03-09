<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkspaceSettingResource;
use App\Models\Workspace;
use App\Services\WorkspaceSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceSettingController extends Controller
{
    public function __construct(private WorkspaceSettingService $settingService) {}

    /**
     * Get workspace settings.
     */
    public function show(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceView);

        $settings = $this->settingService->getOrCreate($workspace);

        return $this->successResponse(
            'Workspace settings retrieved successfully.',
            new WorkspaceSettingResource($settings),
        );
    }

    /**
     * Update workspace settings.
     */
    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceUpdate);

        $validated = $request->validate([
            'timezone' => ['nullable', 'string', 'timezone'],
            'execution_retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'default_max_retries' => ['nullable', 'integer', 'min:1', 'max:10'],
            'default_timeout_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'auto_activate_workflows' => ['nullable', 'boolean'],
            'allow_public_sharing' => ['nullable', 'boolean'],
            'notification_preferences' => ['nullable', 'array'],
            'git_repo_url' => ['nullable', 'string', 'url', 'max:500'],
            'git_branch' => ['nullable', 'string', 'max:100'],
            'git_auto_sync' => ['nullable', 'boolean'],
        ]);

        $settings = $this->settingService->update($workspace, $validated);

        return $this->successResponse(
            'Workspace settings updated successfully.',
            new WorkspaceSettingResource($settings),
        );
    }
}

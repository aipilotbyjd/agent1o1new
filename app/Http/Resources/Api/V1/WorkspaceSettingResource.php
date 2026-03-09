<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkspaceSetting
 */
class WorkspaceSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'timezone' => $this->timezone,
            'execution_retention_days' => $this->execution_retention_days,
            'default_max_retries' => $this->default_max_retries,
            'default_timeout_seconds' => $this->default_timeout_seconds,
            'auto_activate_workflows' => $this->auto_activate_workflows,
            'allow_public_sharing' => $this->allow_public_sharing,
            'notification_preferences' => $this->notification_preferences,
            'git_repo_url' => $this->git_repo_url,
            'git_branch' => $this->git_branch,
            'git_auto_sync' => $this->git_auto_sync,
            'last_git_sync_at' => $this->last_git_sync_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

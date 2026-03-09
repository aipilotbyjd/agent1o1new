<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\WorkspaceSetting;

class WorkspaceSettingService
{
    /**
     * Get or create settings for a workspace.
     */
    public function getOrCreate(Workspace $workspace): WorkspaceSetting
    {
        return WorkspaceSetting::firstOrCreate(
            ['workspace_id' => $workspace->id],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Workspace $workspace, array $data): WorkspaceSetting
    {
        $settings = $this->getOrCreate($workspace);
        $settings->update($data);

        return $settings;
    }
}

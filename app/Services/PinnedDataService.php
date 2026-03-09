<?php

namespace App\Services;

use App\Models\PinnedNodeData;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;

class PinnedDataService
{
    /**
     * @param  array{node_id: string, node_name?: string, data: array}  $data
     */
    public function pin(Workspace $workspace, Workflow $workflow, User $user, array $data): PinnedNodeData
    {
        return PinnedNodeData::updateOrCreate(
            [
                'workflow_id' => $workflow->id,
                'node_id' => $data['node_id'],
            ],
            [
                'workspace_id' => $workspace->id,
                'pinned_by' => $user->id,
                'node_name' => $data['node_name'] ?? null,
                'data' => $data['data'],
                'is_active' => true,
            ],
        );
    }

    public function unpin(PinnedNodeData $pinnedData): void
    {
        $pinnedData->delete();
    }

    public function toggleActive(PinnedNodeData $pinnedData): PinnedNodeData
    {
        $pinnedData->update(['is_active' => ! $pinnedData->is_active]);

        return $pinnedData;
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\WorkflowVersion;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;

class WorkflowService
{
    /**
     * Create a new workflow in the workspace.
     *
     * @param  array{name: string, description?: string, icon?: string, color?: string}  $data
     */
    public function create(Workspace $workspace, User $creator, array $data): Workflow
    {
        return $workspace->workflows()->create([
            ...$data,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Update an existing workflow.
     *
     * @param  array{name?: string, description?: string, icon?: string, color?: string}  $data
     */
    public function update(Workflow $workflow, array $data): Workflow
    {
        if ($workflow->is_locked) {
            throw new ApiException('This workflow is currently locked and cannot be edited.', 423);
        }

        $workflow->update($data);

        return $workflow;
    }

    /**
     * Delete a workflow.
     */
    public function delete(Workflow $workflow): void
    {
        if ($workflow->is_locked) {
            throw new ApiException('This workflow is currently locked and cannot be deleted.', 423);
        }

        $workflow->delete();
    }

    public function duplicate(Workflow $workflow, User $creator): Workflow
    {
        /** @var Workflow $newWorkflow */
        $newWorkflow = $workflow->replicate(['execution_count', 'last_executed_at', 'success_rate', 'current_version_id']);
        $newWorkflow->name = $workflow->name.' (Copy)';
        $newWorkflow->is_active = false;
        $newWorkflow->created_by = $creator->id;
        $newWorkflow->save();

        if ($workflow->current_version_id) {
            $currentVersion = WorkflowVersion::find($workflow->current_version_id);
            
            if ($currentVersion) {
                $newVersion = $currentVersion->replicate(['id', 'workflow_id', 'version_number']);
                $newVersion->workflow_id = $newWorkflow->id;
                $newVersion->version_number = 1;
                $newVersion->created_by = $creator->id;
                $newVersion->is_published = true;
                $newVersion->published_at = now();
                $newVersion->save();

                $newWorkflow->update(['current_version_id' => $newVersion->id]);
            }
        }

        return $newWorkflow;
    }
}

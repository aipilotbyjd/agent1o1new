<?php

namespace App\Services;

use App\Exceptions\ApiException;
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

    /**
     * Duplicate a workflow within the same workspace.
     */
    public function duplicate(Workflow $workflow, User $creator): Workflow
    {
        $newWorkflow = $workflow->replicate(['execution_count', 'last_executed_at', 'success_rate']);
        $newWorkflow->name = $workflow->name.' (Copy)';
        $newWorkflow->is_active = false;
        $newWorkflow->created_by = $creator->id;
        $newWorkflow->save();

        return $newWorkflow;
    }
}

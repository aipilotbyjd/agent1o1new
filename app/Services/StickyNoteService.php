<?php

namespace App\Services;

use App\Models\StickyNote;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;

class StickyNoteService
{
    /**
     * @param  array{content?: string, color?: string, position_x?: float, position_y?: float, width?: float, height?: float, z_index?: int}  $data
     */
    public function create(Workspace $workspace, Workflow $workflow, User $creator, array $data): StickyNote
    {
        return StickyNote::create([
            ...$data,
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StickyNote $stickyNote, array $data): StickyNote
    {
        $stickyNote->update($data);

        return $stickyNote;
    }

    public function delete(StickyNote $stickyNote): void
    {
        $stickyNote->delete();
    }
}

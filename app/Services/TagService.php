<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\Workspace;

class TagService
{
    /**
     * Create a new tag in the workspace.
     *
     * @param  array{name: string, color?: string}  $data
     */
    public function create(Workspace $workspace, array $data): Tag
    {
        return $workspace->tags()->create($data);
    }

    /**
     * Update an existing tag.
     *
     * @param  array{name?: string, color?: string}  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        $tag->update($data);

        return $tag;
    }

    /**
     * Delete a tag.
     */
    public function delete(Tag $tag): void
    {
        $tag->delete();
    }

    /**
     * Attach workflows to a tag (sync without detaching).
     *
     * @param  array<int>  $workflowIds
     */
    public function attachWorkflows(Tag $tag, array $workflowIds): void
    {
        $tag->workflows()->syncWithoutDetaching($workflowIds);
    }

    /**
     * Detach workflows from a tag.
     *
     * @param  array<int>  $workflowIds
     */
    public function detachWorkflows(Tag $tag, array $workflowIds): void
    {
        $tag->workflows()->detach($workflowIds);
    }
}

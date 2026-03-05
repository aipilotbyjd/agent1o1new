<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;

class WorkflowVersionService
{
    /**
     * Create a new version for the given workflow.
     *
     * @param  array{name?: string, description?: string, trigger_type?: string, trigger_config?: array, nodes: array, edges: array, viewport?: array, settings?: array, change_summary?: string}  $data
     */
    public function create(Workflow $workflow, User $creator, array $data): WorkflowVersion
    {
        if ($workflow->is_locked) {
            throw new ApiException('This workflow is locked and cannot be modified.', 423);
        }

        $nextVersion = (int) $workflow->versions()->max('version_number') + 1;

        return $workflow->versions()->create([
            ...$data,
            'version_number' => $nextVersion,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Publish a version, making it the current active version of the workflow.
     */
    public function publish(WorkflowVersion $version): WorkflowVersion
    {
        $workflow = $version->workflow;

        if ($workflow->is_locked) {
            throw new ApiException('This workflow is locked and cannot be modified.', 423);
        }

        if ($version->is_published) {
            throw ApiException::conflict('This version is already published.');
        }

        $version->update([
            'is_published' => true,
            'published_at' => now(),
        ]);

        $workflow->update(['current_version_id' => $version->id]);

        return $version->refresh();
    }

    /**
     * Rollback by cloning a previous version as a new version and publishing it.
     */
    public function rollback(Workflow $workflow, WorkflowVersion $version): WorkflowVersion
    {
        if ($workflow->is_locked) {
            throw new ApiException('This workflow is locked and cannot be modified.', 423);
        }

        $nextVersion = (int) $workflow->versions()->max('version_number') + 1;

        $newVersion = $workflow->versions()->create([
            'version_number' => $nextVersion,
            'name' => $version->name,
            'description' => $version->description,
            'trigger_type' => $version->trigger_type,
            'trigger_config' => $version->trigger_config,
            'nodes' => $version->nodes,
            'edges' => $version->edges,
            'viewport' => $version->viewport,
            'settings' => $version->settings,
            'created_by' => auth()->id(),
            'change_summary' => "Rolled back to version {$version->version_number}",
            'is_published' => true,
            'published_at' => now(),
        ]);

        $workflow->update(['current_version_id' => $newVersion->id]);

        return $newVersion;
    }

    /**
     * Compute a diff summary between two versions.
     *
     * @return array{added: array, removed: array, modified: array}
     */
    public function diff(WorkflowVersion $from, WorkflowVersion $to): array
    {
        $fromNodes = collect($from->nodes)->keyBy('id');
        $toNodes = collect($to->nodes)->keyBy('id');

        $added = $toNodes->diffKeys($fromNodes)->values()->map(fn ($n) => [
            'id' => $n['id'],
            'type' => $n['type'] ?? null,
        ])->all();

        $removed = $fromNodes->diffKeys($toNodes)->values()->map(fn ($n) => [
            'id' => $n['id'],
            'type' => $n['type'] ?? null,
        ])->all();

        $modified = [];
        foreach ($toNodes->intersectByKeys($fromNodes) as $id => $toNode) {
            $fromNode = $fromNodes[$id];
            if ($toNode !== $fromNode) {
                $modified[] = [
                    'id' => $id,
                    'type' => $toNode['type'] ?? null,
                ];
            }
        }

        return [
            'from_version' => $from->version_number,
            'to_version' => $to->version_number,
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ];
    }
}

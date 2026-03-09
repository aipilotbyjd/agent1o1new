<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;

class WorkflowImportExportService
{
    /**
     * Export a workflow as a portable JSON structure.
     *
     * @return array<string, mixed>
     */
    public function export(Workflow $workflow): array
    {
        $workflow->loadMissing(['currentVersion', 'tags', 'webhooks']);

        $version = $workflow->currentVersion;

        return [
            'format_version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'workflow' => [
                'name' => $workflow->name,
                'description' => $workflow->description,
                'icon' => $workflow->icon,
                'color' => $workflow->color,
                'trigger_type' => $workflow->trigger_type,
                'cron_expression' => $workflow->cron_expression,
            ],
            'version' => $version ? [
                'trigger_type' => $version->trigger_type,
                'trigger_config' => $version->trigger_config,
                'nodes' => $version->nodes,
                'edges' => $version->edges,
                'viewport' => $version->viewport,
                'settings' => $version->settings,
            ] : null,
            'tags' => $workflow->tags->pluck('name')->toArray(),
            'required_credentials' => $this->extractRequiredCredentials($version),
        ];
    }

    /**
     * Import a workflow from a portable JSON structure.
     *
     * @param  array<string, mixed>  $data
     */
    public function import(array $data, Workspace $workspace, User $creator): Workflow
    {
        if (! isset($data['workflow']) || ! isset($data['format_version'])) {
            throw ApiException::unprocessable('Invalid workflow export format.');
        }

        $workflowData = $data['workflow'];

        $workflow = $workspace->workflows()->create([
            'name' => $workflowData['name'] ?? 'Imported Workflow',
            'description' => $workflowData['description'] ?? null,
            'icon' => $workflowData['icon'] ?? null,
            'color' => $workflowData['color'] ?? null,
            'created_by' => $creator->id,
            'trigger_type' => $workflowData['trigger_type'] ?? null,
            'cron_expression' => $workflowData['cron_expression'] ?? null,
        ]);

        if (! empty($data['version'])) {
            $versionData = $data['version'];

            $version = WorkflowVersion::create([
                'workflow_id' => $workflow->id,
                'version_number' => 1,
                'name' => 'Imported version',
                'trigger_type' => $versionData['trigger_type'] ?? null,
                'trigger_config' => $versionData['trigger_config'] ?? null,
                'nodes' => $versionData['nodes'] ?? [],
                'edges' => $versionData['edges'] ?? [],
                'viewport' => $versionData['viewport'] ?? null,
                'settings' => $versionData['settings'] ?? [],
                'created_by' => $creator->id,
                'is_published' => true,
                'published_at' => now(),
            ]);

            $workflow->update(['current_version_id' => $version->id]);
        }

        return $workflow->load('creator');
    }

    /**
     * @return array<int, string>
     */
    private function extractRequiredCredentials(?WorkflowVersion $version): array
    {
        if (! $version || empty($version->nodes)) {
            return [];
        }

        $types = [];
        foreach ($version->nodes as $node) {
            if (! empty($node['credentials'])) {
                foreach ($node['credentials'] as $credType => $credInfo) {
                    $types[] = $credType;
                }
            }
        }

        return array_unique($types);
    }
}

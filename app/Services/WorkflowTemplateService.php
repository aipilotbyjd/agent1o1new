<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Models\Workspace;

class WorkflowTemplateService
{
    /**
     * Create a workflow from a template.
     */
    public function useTemplate(WorkflowTemplate $template, Workspace $workspace, User $creator): Workflow
    {
        $workflow = $workspace->workflows()->create([
            'name' => $template->name,
            'description' => $template->description,
            'icon' => $template->icon,
            'color' => $template->color,
            'created_by' => $creator->id,
            'trigger_type' => $template->trigger_type,
        ]);

        $version = WorkflowVersion::create([
            'workflow_id' => $workflow->id,
            'version_number' => 1,
            'name' => 'Initial version from template',
            'description' => "Created from template: {$template->name}",
            'trigger_type' => $template->trigger_type,
            'trigger_config' => $template->trigger_config,
            'nodes' => $template->nodes,
            'edges' => $template->edges,
            'viewport' => $template->viewport,
            'settings' => $template->settings,
            'created_by' => $creator->id,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $workflow->update(['current_version_id' => $version->id]);

        $template->increment('usage_count');

        return $workflow->load('creator');
    }
}

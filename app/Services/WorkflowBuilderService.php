<?php

namespace App\Services;

use App\Ai\Agents\WorkflowBuilderAgent;
use App\Models\AiGenerationLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowBuilderService
{
    /**
     * Generate a workflow from a natural language description.
     *
     * The agent produces a structured JSON payload containing the workflow
     * name, description, nodes, and edges. We then persist:
     *   1. The Workflow record
     *   2. A published WorkflowVersion (version 1) with the generated nodes/edges
     *   3. An AiGenerationLog for auditing / feedback purposes
     *
     * @throws Throwable
     */
    public function build(Workspace $workspace, User $creator, string $description): Workflow
    {
        $agent = new WorkflowBuilderAgent;

        /** @var array{workflow_name: string, workflow_description: string, nodes: array<int, mixed>, edges: array<int, mixed>} $generated */
        $generated = $agent->prompt($description);

        return DB::transaction(function () use ($workspace, $creator, $description, $generated): Workflow {
            $workflow = $workspace->workflows()->create([
                'name' => $generated['workflow_name'],
                'description' => $generated['workflow_description'],
                'created_by' => $creator->id,
                'is_active' => false,
            ]);

            $version = WorkflowVersion::create([
                'workflow_id' => $workflow->id,
                'version_number' => 1,
                'name' => 'AI Generated',
                'description' => "Generated from: {$description}",
                'nodes' => $generated['nodes'],
                'edges' => $generated['edges'],
                'created_by' => $creator->id,
                'is_published' => true,
                'published_at' => now(),
                'change_summary' => 'Initial AI-generated workflow.',
            ]);

            $workflow->update(['current_version_id' => $version->id]);

            AiGenerationLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $creator->id,
                'prompt' => $description,
                'generated_json' => $generated,
                'model_used' => config('ai.default', 'openai'),
                'status' => 'draft',
                'workflow_id' => $workflow->id,
            ]);

            return $workflow->load(['creator', 'currentVersion']);
        });
    }
}

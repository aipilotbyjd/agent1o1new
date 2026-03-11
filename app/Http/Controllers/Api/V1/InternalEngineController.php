<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal API endpoints called by the Go Engine.
 * Protected by engine.signature middleware (HMAC verification).
 */
class InternalEngineController extends Controller
{
    /**
     * Serve credential data for a specific execution + node.
     * GET /api/v1/internal/credentials/{executionId}/{nodeId}
     *
     * The engine calls this just-in-time when a node needs credentials,
     * instead of receiving them pre-embedded in the Redis job payload.
     */
    public function credential(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'execution_id' => 'required|integer',
            'node_id' => 'required|string',
        ]);

        $executionId = $validated['execution_id'];
        $nodeId = $validated['node_id'];

        $execution = Execution::find($executionId);

        if (! $execution) {
            return response()->json(['success' => false, 'error' => 'Execution not found'], 404);
        }

        $workflow = $execution->workflow;

        if (! $workflow) {
            return response()->json(['success' => false, 'error' => 'Workflow not found'], 404);
        }

        // Find credential linked to this workflow for the specified node
        $credential = $workflow->credentials()
            ->wherePivot('node_id', $nodeId)
            ->first();

        if (! $credential) {
            return response()->json(['success' => false, 'error' => 'Credential not found for node'], 404);
        }

        $data = json_decode($credential->data, true) ?? [];

        // Update last_used_at for audit trail
        $credential->update(['last_used_at' => now()]);

        return response()->json([
            'success' => true,
            'credential' => [
                'id' => $credential->id,
                'type' => $credential->type,
                'name' => $credential->name,
                'data' => $data,
            ],
        ]);
    }

    /**
     * Serve workflow definition by ID (with optional version hash).
     * GET /api/v1/internal/workflows/{workflowId}/definition
     *
     * The engine caches this by version_hash (immutable) to avoid
     * embedding full workflow definitions in every Redis message.
     */
    public function workflowDefinition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workflow_id' => 'required|integer',
            'version_hash' => 'nullable|string',
        ]);

        $workflowId = $validated['workflow_id'];
        $wfHash = $validated['version_hash'] ?? null;

        $workflow = Workflow::find($workflowId);

        if (! $workflow) {
            return response()->json(['success' => false, 'error' => 'Workflow not found'], 404);
        }

        if ($wfHash) {
            $version = WorkflowVersion::query()
                ->where('workflow_id', $workflowId)
                ->where('version_hash', $wfHash)
                ->first();
        } else {
            $version = $workflow->currentVersion;
        }

        if (! $version) {
            return response()->json(['success' => false, 'error' => 'Version not found'], 404);
        }

        return response()->json([
            'success' => true,
            'workflow' => [
                'workflow_id' => $workflow->id,
                'version_id' => $version->id,
                'version_hash' => $version->version_hash ?? '',
                'nodes' => $version->nodes ?? [],
                'edges' => $version->edges ?? [],
                'settings' => $version->settings ?? [],
            ],
        ]);
    }
}

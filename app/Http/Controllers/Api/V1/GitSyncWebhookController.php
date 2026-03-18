<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Services\GitSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GitSyncWebhookController extends Controller
{
    public function __construct(private GitSyncService $gitSyncService) {}

    /**
     * Handle a Git push webhook for a workspace.
     */
    public function handle(Request $request, string $workspaceSlug): JsonResponse
    {
        $workspace = Workspace::where('slug', $workspaceSlug)->first();

        if (! $workspace) {
            return $this->errorResponse('Workspace not found.', 404);
        }

        $settings = WorkspaceSetting::where('workspace_id', $workspace->id)->first();

        if (! $this->verifySignature($request, $settings)) {
            return $this->errorResponse('Invalid webhook signature.', 403);
        }

        try {
            $result = $this->gitSyncService->processWebhookPush(
                $request->all(),
                $workspace,
            );

            return $this->successResponse('Git sync webhook processed.', $result);
        } catch (\Throwable $e) {
            Log::warning('Git sync webhook failed', [
                'workspace' => $workspaceSlug,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    private function verifySignature(Request $request, ?WorkspaceSetting $settings): bool
    {
        $secret = $settings?->git_sync_config['webhook_secret'] ?? null;

        if (! $secret) {
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Gitlab-Token');

        if (! $signature) {
            return false;
        }

        // GitHub-style: sha256=<hex>
        if (str_starts_with($signature, 'sha256=')) {
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            return hash_equals($expected, $signature);
        }

        // GitLab-style: token comparison
        return hash_equals($secret, $signature);
    }
}

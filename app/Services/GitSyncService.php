<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;

class GitSyncService
{
    public function __construct(
        private WorkflowImportExportService $importExportService,
    ) {}

    /**
     * Export all workspace workflows to a Git-compatible structure.
     *
     * @return array<string, mixed>
     */
    public function exportAll(Workspace $workspace): array
    {
        $workflows = $workspace->workflows()->with(['currentVersion', 'tags'])->get();

        $exports = [];
        foreach ($workflows as $workflow) {
            $slug = \Illuminate\Support\Str::slug($workflow->name).'-'.$workflow->id;
            $exports[$slug] = $this->importExportService->export($workflow);
        }

        return [
            'workspace' => [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'exported_at' => now()->toIso8601String(),
            'workflows' => $exports,
        ];
    }

    /**
     * Import workflows from a Git sync payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importAll(array $payload, Workspace $workspace, User $user): array
    {
        $workflows = $payload['workflows'] ?? [];

        if (empty($workflows)) {
            throw \App\Exceptions\ApiException::unprocessable('No workflows found in the import payload.');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($workflows as $slug => $workflowData) {
            try {
                $this->importExportService->import($workflowData, $workspace, $user);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Failed to import '{$slug}': {$e->getMessage()}";
                $skipped++;
            }
        }

        WorkspaceSetting::updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['last_git_sync_at' => now()],
        );

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Process a Git webhook push event.
     *
     * @param  array<string, mixed>  $payload
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function processWebhookPush(array $payload, Workspace $workspace): array
    {
        $settings = WorkspaceSetting::where('workspace_id', $workspace->id)->first();

        if (! $settings || ! $settings->git_auto_sync) {
            throw \App\Exceptions\ApiException::unprocessable('Git auto-sync is not enabled for this workspace.');
        }

        $targetBranch = $settings->git_branch ?? 'main';
        $pushBranch = $payload['ref'] ?? '';

        if (! str_ends_with($pushBranch, "/{$targetBranch}")) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Push was to a different branch, skipped.']];
        }

        $workflows = $payload['workflows'] ?? [];

        if (empty($workflows)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['No workflows in push payload.']];
        }

        $owner = $workspace->owner;

        return $this->importAll(['workflows' => $workflows], $workspace, $owner);
    }

    /**
     * Get sync status.
     *
     * @return array{configured: bool, last_sync_at: string|null, auto_sync: bool, repo_url: string|null, branch: string}
     */
    public function status(Workspace $workspace): array
    {
        $settings = WorkspaceSetting::where('workspace_id', $workspace->id)->first();

        return [
            'configured' => $settings && ! empty($settings->git_repo_url),
            'last_sync_at' => $settings?->last_git_sync_at?->toIso8601String(),
            'auto_sync' => $settings?->git_auto_sync ?? false,
            'repo_url' => $settings?->git_repo_url,
            'branch' => $settings?->git_branch ?? 'main',
        ];
    }
}

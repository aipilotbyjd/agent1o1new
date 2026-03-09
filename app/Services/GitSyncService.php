<?php

namespace App\Services;

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

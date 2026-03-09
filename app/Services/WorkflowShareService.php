<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowShare;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkflowShareService
{
    /**
     * @param  array{is_public?: bool, allow_clone?: bool, password?: string, expires_at?: string}  $data
     */
    public function create(Workspace $workspace, Workflow $workflow, User $sharedBy, array $data): WorkflowShare
    {
        $existing = $workflow->shares()->where('is_public', true)->first();
        if ($existing) {
            throw ApiException::conflict('This workflow already has an active public share link.');
        }

        return WorkflowShare::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
            'shared_by' => $sharedBy->id,
            'share_token' => (string) Str::uuid(),
            'is_public' => $data['is_public'] ?? true,
            'allow_clone' => $data['allow_clone'] ?? true,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkflowShare $share, array $data): WorkflowShare
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $share->update($data);

        return $share;
    }

    public function delete(WorkflowShare $share): void
    {
        $share->delete();
    }

    /**
     * View a shared workflow by token (public access).
     *
     * @return array<string, mixed>
     */
    public function viewByToken(string $token, ?string $password = null): array
    {
        $share = WorkflowShare::where('share_token', $token)->firstOrFail();

        if (! $share->isAccessible()) {
            throw ApiException::notFound('Share link not found or expired.');
        }

        if ($share->password && ! Hash::check($password ?? '', $share->password)) {
            throw ApiException::forbidden('Invalid password.');
        }

        $share->increment('view_count');

        $share->loadMissing(['workflow.currentVersion', 'workflow.tags']);

        return [
            'workflow' => $share->workflow,
            'allow_clone' => $share->allow_clone,
        ];
    }

    /**
     * Clone a shared workflow into a workspace.
     */
    public function cloneFromShare(string $token, Workspace $workspace, User $creator, ?string $password = null): \App\Models\Workflow
    {
        $share = WorkflowShare::where('share_token', $token)->firstOrFail();

        if (! $share->isAccessible()) {
            throw ApiException::notFound('Share link not found or expired.');
        }

        if (! $share->allow_clone) {
            throw ApiException::forbidden('Cloning is not allowed for this share link.');
        }

        if ($share->password && ! Hash::check($password ?? '', $share->password)) {
            throw ApiException::forbidden('Invalid password.');
        }

        $share->increment('clone_count');

        $importExportService = app(WorkflowImportExportService::class);
        $exportData = $importExportService->export($share->workflow);

        return $importExportService->import($exportData, $workspace, $creator);
    }
}

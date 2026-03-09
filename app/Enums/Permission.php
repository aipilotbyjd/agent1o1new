<?php

namespace App\Enums;

enum Permission: string
{
    // ── Workspace ────────────────────────────────────────────
    case WorkspaceView = 'workspace.view';
    case WorkspaceUpdate = 'workspace.update';
    case WorkspaceDelete = 'workspace.delete';
    case WorkspaceManageBilling = 'workspace.manage-billing';
    case WorkspaceTransferOwnership = 'workspace.transfer-ownership';

    // ── Members ──────────────────────────────────────────────
    case MemberView = 'member.view';
    case MemberInvite = 'member.invite';
    case MemberUpdate = 'member.update';
    case MemberRemove = 'member.remove';

    // ── Workflows ────────────────────────────────────────────
    case WorkflowView = 'workflow.view';
    case WorkflowCreate = 'workflow.create';
    case WorkflowUpdate = 'workflow.update';
    case WorkflowDelete = 'workflow.delete';
    case WorkflowExecute = 'workflow.execute';
    case WorkflowActivate = 'workflow.activate';
    case WorkflowShare = 'workflow.share';
    case WorkflowDuplicate = 'workflow.duplicate';
    case WorkflowExport = 'workflow.export';
    case WorkflowImport = 'workflow.import';

    // ── Workflow Versions ────────────────────────────────────
    case VersionView = 'version.view';
    case VersionRestore = 'version.restore';

    // ── Workflow Templates ───────────────────────────────────
    case TemplateView = 'template.view';
    case TemplateCreate = 'template.create';
    case TemplateUpdate = 'template.update';
    case TemplateDelete = 'template.delete';

    // ── Workflow Approvals ───────────────────────────────────
    case ApprovalView = 'approval.view';
    case ApprovalRequest = 'approval.request';
    case ApprovalApprove = 'approval.approve';
    case ApprovalReject = 'approval.reject';

    // ── Workflow Contracts ───────────────────────────────────
    case ContractView = 'contract.view';
    case ContractTest = 'contract.test';

    // ── Credentials ──────────────────────────────────────────
    case CredentialView = 'credential.view';
    case CredentialCreate = 'credential.create';
    case CredentialUpdate = 'credential.update';
    case CredentialDelete = 'credential.delete';
    case CredentialShare = 'credential.share';
    case CredentialTest = 'credential.test';

    // ── Executions ───────────────────────────────────────────
    case ExecutionView = 'execution.view';
    case ExecutionDelete = 'execution.delete';
    case ExecutionRetry = 'execution.retry';
    case ExecutionCancel = 'execution.cancel';
    case ExecutionDebug = 'execution.debug';
    case ExecutionReplay = 'execution.replay';

    // ── Webhooks ─────────────────────────────────────────────
    case WebhookView = 'webhook.view';
    case WebhookCreate = 'webhook.create';
    case WebhookUpdate = 'webhook.update';
    case WebhookDelete = 'webhook.delete';

    // ── Tags ─────────────────────────────────────────────────
    case TagView = 'tag.view';
    case TagCreate = 'tag.create';
    case TagUpdate = 'tag.update';
    case TagDelete = 'tag.delete';

    // ── Variables ────────────────────────────────────────────
    case VariableView = 'variable.view';
    case VariableCreate = 'variable.create';
    case VariableUpdate = 'variable.update';
    case VariableDelete = 'variable.delete';

    // ── Environments ─────────────────────────────────────────
    case EnvironmentView = 'environment.view';
    case EnvironmentCreate = 'environment.create';
    case EnvironmentUpdate = 'environment.update';
    case EnvironmentDelete = 'environment.delete';
    case EnvironmentDeploy = 'environment.deploy';

    // ── AI Features ──────────────────────────────────────────
    case AiGenerate = 'ai.generate';
    case AiAutofix = 'ai.autofix';

    // ── Activity Logs ────────────────────────────────────────
    case ActivityLogView = 'activity-log.view';

    // ── Audit Logs ───────────────────────────────────────────
    case AuditLogView = 'audit-log.view';
    case AuditLogExport = 'audit-log.export';

    // ── Credits ──────────────────────────────────────────────
    case CreditView = 'credit.view';
    case CreditPurchase = 'credit.purchase';

    // ── Connector Metrics ────────────────────────────────────
    case ConnectorViewMetrics = 'connector.view-metrics';

    // ── Workspace Settings ──────────────────────────────────
    case SettingView = 'setting.view';
    case SettingUpdate = 'setting.update';

    // ── Log Streaming ───────────────────────────────────────
    case LogStreamView = 'log-stream.view';
    case LogStreamCreate = 'log-stream.create';
    case LogStreamUpdate = 'log-stream.update';
    case LogStreamDelete = 'log-stream.delete';

    // ── Git Sync ────────────────────────────────────────────
    case GitSyncView = 'git-sync.view';
    case GitSyncExport = 'git-sync.export';
    case GitSyncImport = 'git-sync.import';

    // ── Sticky Notes ────────────────────────────────────────
    case StickyNoteView = 'sticky-note.view';
    case StickyNoteCreate = 'sticky-note.create';
    case StickyNoteUpdate = 'sticky-note.update';
    case StickyNoteDelete = 'sticky-note.delete';

    // ── Pinned Data ─────────────────────────────────────────
    case PinnedDataView = 'pinned-data.view';
    case PinnedDataManage = 'pinned-data.manage';

    // ── Helpers ──────────────────────────────────────────────

    /**
     * Permissions grouped by resource (useful for frontend UI).
     *
     * @return array<string, array<string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $case) {
            $resource = explode('.', $case->value)[0];
            $groups[$resource][] = $case->value;
        }

        return $groups;
    }
}

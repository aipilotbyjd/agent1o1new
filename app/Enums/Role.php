<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * @return array<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            self::Admin => [
                // Workspace
                Permission::WorkspaceView,
                Permission::WorkspaceUpdate,

                // Members
                Permission::MemberView,
                Permission::MemberInvite,
                Permission::MemberUpdate,
                Permission::MemberRemove,

                // Workflows
                Permission::WorkflowView,
                Permission::WorkflowCreate,
                Permission::WorkflowUpdate,
                Permission::WorkflowDelete,
                Permission::WorkflowExecute,
                Permission::WorkflowActivate,
                Permission::WorkflowShare,
                Permission::WorkflowDuplicate,
                Permission::WorkflowExport,
                Permission::WorkflowImport,

                // Versions
                Permission::VersionView,
                Permission::VersionRestore,

                // Templates
                Permission::TemplateView,
                Permission::TemplateCreate,
                Permission::TemplateUpdate,
                Permission::TemplateDelete,

                // Approvals
                Permission::ApprovalView,
                Permission::ApprovalRequest,
                Permission::ApprovalApprove,
                Permission::ApprovalReject,

                // Contracts
                Permission::ContractView,
                Permission::ContractTest,

                // Credentials
                Permission::CredentialView,
                Permission::CredentialCreate,
                Permission::CredentialUpdate,
                Permission::CredentialDelete,
                Permission::CredentialShare,
                Permission::CredentialTest,

                // Executions
                Permission::ExecutionView,
                Permission::ExecutionDelete,
                Permission::ExecutionRetry,
                Permission::ExecutionCancel,
                Permission::ExecutionDebug,
                Permission::ExecutionReplay,

                // Webhooks
                Permission::WebhookView,
                Permission::WebhookCreate,
                Permission::WebhookUpdate,
                Permission::WebhookDelete,

                // Polling Triggers
                Permission::PollingTriggerView,
                Permission::PollingTriggerCreate,
                Permission::PollingTriggerUpdate,
                Permission::PollingTriggerDelete,

                // Tags
                Permission::TagView,
                Permission::TagCreate,
                Permission::TagUpdate,
                Permission::TagDelete,

                // Variables
                Permission::VariableView,
                Permission::VariableCreate,
                Permission::VariableUpdate,
                Permission::VariableDelete,

                // Environments
                Permission::EnvironmentView,
                Permission::EnvironmentCreate,
                Permission::EnvironmentUpdate,
                Permission::EnvironmentDelete,
                Permission::EnvironmentDeploy,

                // AI
                Permission::AiGenerate,
                Permission::AiAutofix,

                // Logs
                Permission::ActivityLogView,
                Permission::AuditLogView,
                Permission::AuditLogExport,

                // Credits
                Permission::CreditView,

                // Connectors
                Permission::ConnectorViewMetrics,
            ],

            self::Member => [
                // Workspace
                Permission::WorkspaceView,

                // Members
                Permission::MemberView,

                // Workflows (no delete, no share)
                Permission::WorkflowView,
                Permission::WorkflowCreate,
                Permission::WorkflowUpdate,
                Permission::WorkflowExecute,
                Permission::WorkflowActivate,
                Permission::WorkflowDuplicate,
                Permission::WorkflowExport,
                Permission::WorkflowImport,

                // Versions
                Permission::VersionView,

                // Templates (no delete)
                Permission::TemplateView,
                Permission::TemplateCreate,
                Permission::TemplateUpdate,

                // Approvals (view & request only)
                Permission::ApprovalView,
                Permission::ApprovalRequest,

                // Contracts
                Permission::ContractView,
                Permission::ContractTest,

                // Credentials (no delete, no share)
                Permission::CredentialView,
                Permission::CredentialCreate,
                Permission::CredentialUpdate,
                Permission::CredentialTest,

                // Executions (no delete, no debug, no replay)
                Permission::ExecutionView,
                Permission::ExecutionRetry,
                Permission::ExecutionCancel,

                // Webhooks (no delete)
                Permission::WebhookView,
                Permission::WebhookCreate,
                Permission::WebhookUpdate,

                // Polling Triggers (no delete)
                Permission::PollingTriggerView,
                Permission::PollingTriggerCreate,
                Permission::PollingTriggerUpdate,

                // Tags (no delete)
                Permission::TagView,
                Permission::TagCreate,
                Permission::TagUpdate,

                // Variables (no delete)
                Permission::VariableView,
                Permission::VariableCreate,
                Permission::VariableUpdate,

                // Environments (view only)
                Permission::EnvironmentView,

                // AI
                Permission::AiGenerate,
                Permission::AiAutofix,

                // Logs
                Permission::ActivityLogView,

                // Credits
                Permission::CreditView,

                // Connectors
                Permission::ConnectorViewMetrics,
            ],

            self::Viewer => [
                Permission::WorkspaceView,
                Permission::MemberView,
                Permission::WorkflowView,
                Permission::VersionView,
                Permission::TemplateView,
                Permission::ApprovalView,
                Permission::ContractView,
                Permission::CredentialView,
                Permission::ExecutionView,
                Permission::WebhookView,
                Permission::PollingTriggerView,
                Permission::TagView,
                Permission::VariableView,
                Permission::EnvironmentView,
                Permission::ActivityLogView,
                Permission::CreditView,
            ],
        };
    }

    /**
     * Permission string values for this role.
     *
     * @return array<string>
     */
    public function permissionValues(): array
    {
        return array_map(fn (Permission $p) => $p->value, $this->permissions());
    }

    /**
     * Roles that can be assigned to members (Owner is never assignable).
     *
     * @return array<self>
     */
    public static function assignable(): array
    {
        return [self::Viewer, self::Member, self::Admin];
    }
}

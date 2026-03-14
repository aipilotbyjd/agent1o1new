<?php

namespace App\Engine\Enums;

use App\Engine\Nodes\Apps\Google\GmailNode;
use App\Engine\Nodes\Apps\Google\GoogleCalendarNode;
use App\Engine\Nodes\Apps\Google\GoogleDriveNode;
use App\Engine\Nodes\Apps\Google\GoogleSheetsNode;
use App\Engine\Nodes\Core\HttpRequestNode;
use App\Engine\Nodes\Core\SetVariableNode;
use App\Engine\Nodes\Core\SubWorkflowNode;
use App\Engine\Nodes\Core\TransformNode;
use App\Engine\Nodes\Core\TriggerNode;
use App\Engine\Nodes\Flow\ConditionNode;
use App\Engine\Nodes\Flow\DelayNode;
use App\Engine\Nodes\Flow\LoopNode;
use App\Engine\Nodes\Flow\MergeNode;

enum NodeType: string
{
    // ── Core ──────────────────────────────────────────────────
    case Trigger = 'trigger';
    case HttpRequest = 'http_request';
    case Transform = 'transform';
    case Code = 'code';
    case SetVariable = 'set_variable';
    case SubWorkflow = 'sub_workflow';

    // ── Flow Control ─────────────────────────────────────────
    case Condition = 'condition';
    case IfBranch = 'if';
    case Switch = 'switch';
    case Merge = 'merge';
    case Loop = 'loop';
    case Delay = 'delay';
    case Wait = 'wait';

    // ── Apps: Google ─────────────────────────────────────────
    case GoogleSheetsGetRows = 'google_sheets.get_rows';
    case GoogleSheetsAppendRow = 'google_sheets.append_row';
    case GoogleSheetsUpdateRow = 'google_sheets.update_row';
    case GmailSendEmail = 'gmail.send_email';
    case GmailAddLabel = 'gmail.add_label';
    case GmailListMessages = 'gmail.list_messages';
    case GoogleDriveListFiles = 'google_drive.list_files';
    case GoogleDriveCreateFolder = 'google_drive.create_folder';
    case GoogleDriveUploadFile = 'google_drive.upload_file';
    case GoogleCalendarListEvents = 'google_calendar.list_events';
    case GoogleCalendarCreateEvent = 'google_calendar.create_event';
    case GoogleCalendarUpdateEvent = 'google_calendar.update_event';
    case GoogleCalendarDeleteEvent = 'google_calendar.delete_event';

    /**
     * @return class-string<\App\Engine\Contracts\NodeHandler>
     */
    public function handlerClass(): string
    {
        return match ($this) {
            // Core
            self::Trigger => TriggerNode::class,
            self::HttpRequest => HttpRequestNode::class,
            self::Transform, self::Code => TransformNode::class,
            self::SetVariable => SetVariableNode::class,
            self::SubWorkflow => SubWorkflowNode::class,

            // Flow
            self::Condition, self::IfBranch, self::Switch => ConditionNode::class,
            self::Merge => MergeNode::class,
            self::Loop => LoopNode::class,
            self::Delay, self::Wait => DelayNode::class,

            // Google Sheets
            self::GoogleSheetsGetRows,
            self::GoogleSheetsAppendRow,
            self::GoogleSheetsUpdateRow => GoogleSheetsNode::class,

            // Gmail
            self::GmailSendEmail,
            self::GmailAddLabel,
            self::GmailListMessages => GmailNode::class,

            // Google Drive
            self::GoogleDriveListFiles,
            self::GoogleDriveCreateFolder,
            self::GoogleDriveUploadFile => GoogleDriveNode::class,

            // Google Calendar
            self::GoogleCalendarListEvents,
            self::GoogleCalendarCreateEvent,
            self::GoogleCalendarUpdateEvent,
            self::GoogleCalendarDeleteEvent => GoogleCalendarNode::class,
        };
    }

    /**
     * Whether this node type executes synchronously (no I/O).
     */
    public function isSync(): bool
    {
        return match ($this) {
            self::HttpRequest,
            self::GoogleSheetsGetRows, self::GoogleSheetsAppendRow, self::GoogleSheetsUpdateRow,
            self::GmailSendEmail, self::GmailAddLabel, self::GmailListMessages,
            self::GoogleDriveListFiles, self::GoogleDriveCreateFolder, self::GoogleDriveUploadFile,
            self::GoogleCalendarListEvents, self::GoogleCalendarCreateEvent, self::GoogleCalendarUpdateEvent, self::GoogleCalendarDeleteEvent => false,
            self::Delay, self::Wait => false,
            default => true,
        };
    }

    /**
     * Whether this node type may suspend the execution (checkpoint + requeue).
     */
    public function isSuspendable(): bool
    {
        return match ($this) {
            self::Delay, self::Wait => true,
            default => false,
        };
    }

    /**
     * Resolve a node type string to its enum case, falling back gracefully.
     */
    public static function resolve(string $type): ?self
    {
        return self::tryFrom($type);
    }
}

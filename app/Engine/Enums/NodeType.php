<?php

namespace App\Engine\Enums;

use App\Engine\Nodes\Core\HttpRequestNode;
use App\Engine\Nodes\Core\SetVariableNode;
use App\Engine\Nodes\Core\SubWorkflowNode;
use App\Engine\Nodes\Core\TransformNode;
use App\Engine\Nodes\Core\TriggerNode;
use App\Engine\Nodes\Flow\ConditionNode;
use App\Engine\Nodes\Flow\DelayNode;
use App\Engine\Nodes\Flow\LoopNode;
use App\Engine\Nodes\Flow\MergeNode;

/**
 * Core and flow-control node types.
 *
 * App nodes (google_sheets.*, slack.*, etc.) are resolved dynamically
 * by NodeRegistry via naming convention — they do NOT need enum cases.
 */
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

    /**
     * @return class-string<\App\Engine\Contracts\NodeHandler>
     */
    public function handlerClass(): string
    {
        return match ($this) {
            self::Trigger => TriggerNode::class,
            self::HttpRequest => HttpRequestNode::class,
            self::Transform, self::Code => TransformNode::class,
            self::SetVariable => SetVariableNode::class,
            self::SubWorkflow => SubWorkflowNode::class,
            self::Condition, self::IfBranch, self::Switch => ConditionNode::class,
            self::Merge => MergeNode::class,
            self::Loop => LoopNode::class,
            self::Delay, self::Wait => DelayNode::class,
        };
    }

    /**
     * Whether this node type executes synchronously (no I/O).
     */
    public function isSync(): bool
    {
        return match ($this) {
            self::HttpRequest => false,
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

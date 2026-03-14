<?php

namespace App\Engine\Enums;

use App\Engine\Nodes\ConditionNode;
use App\Engine\Nodes\DelayNode;
use App\Engine\Nodes\HttpRequestNode;
use App\Engine\Nodes\LoopNode;
use App\Engine\Nodes\MergeNode;
use App\Engine\Nodes\SetVariableNode;
use App\Engine\Nodes\SubWorkflowNode;
use App\Engine\Nodes\TransformNode;
use App\Engine\Nodes\TriggerNode;

enum NodeType: string
{
    case Trigger = 'trigger';
    case HttpRequest = 'http_request';
    case Transform = 'transform';
    case Code = 'code';
    case Condition = 'condition';
    case IfBranch = 'if';
    case Switch = 'switch';
    case SetVariable = 'set_variable';
    case Merge = 'merge';
    case Loop = 'loop';
    case Delay = 'delay';
    case Wait = 'wait';
    case SubWorkflow = 'sub_workflow';

    /**
     * @return class-string<\App\Engine\Contracts\NodeHandler>
     */
    public function handlerClass(): string
    {
        return match ($this) {
            self::Trigger => TriggerNode::class,
            self::HttpRequest => HttpRequestNode::class,
            self::Transform, self::Code => TransformNode::class,
            self::Condition, self::IfBranch, self::Switch => ConditionNode::class,
            self::SetVariable => SetVariableNode::class,
            self::Merge => MergeNode::class,
            self::Loop => LoopNode::class,
            self::Delay, self::Wait => DelayNode::class,
            self::SubWorkflow => SubWorkflowNode::class,
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

<?php

namespace App\Engine;

use App\Enums\ExecutionNodeStatus;

class NodeResult
{
    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>|null  $error
     * @param  list<string>|null  $activeBranches  Output branch keys for conditional nodes.
     * @param  list<mixed>|null  $loopItems  Items to iterate for loop nodes.
     */
    public function __construct(
        public readonly ExecutionNodeStatus $status,
        public readonly ?array $output = null,
        public readonly ?array $error = null,
        public readonly ?int $durationMs = null,
        public readonly ?array $activeBranches = null,
        public readonly ?array $loopItems = null,
    ) {}

    public static function completed(array $output, int $durationMs = 0): self
    {
        return new self(
            status: ExecutionNodeStatus::Completed,
            output: $output,
            durationMs: $durationMs,
        );
    }

    public static function failed(string $message, ?string $code = null, int $durationMs = 0): self
    {
        return new self(
            status: ExecutionNodeStatus::Failed,
            error: array_filter(['message' => $message, 'code' => $code]),
            durationMs: $durationMs,
        );
    }

    public static function skipped(string $reason = 'Branch not active'): self
    {
        return new self(
            status: ExecutionNodeStatus::Skipped,
            output: ['reason' => $reason],
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === ExecutionNodeStatus::Completed;
    }
}

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

    /**
     * Serialize to a plain array for cross-process transport.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'output' => $this->output,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'active_branches' => $this->activeBranches,
            'loop_items' => $this->loopItems,
        ];
    }

    /**
     * Reconstruct a NodeResult from a plain array (e.g. from a child process).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: ExecutionNodeStatus::from($data['status']),
            output: $data['output'] ?? null,
            error: $data['error'] ?? null,
            durationMs: $data['duration_ms'] ?? null,
            activeBranches: $data['active_branches'] ?? null,
            loopItems: $data['loop_items'] ?? null,
        );
    }
}

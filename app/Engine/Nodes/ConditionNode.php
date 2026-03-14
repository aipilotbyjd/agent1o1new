<?php

namespace App\Engine\Nodes;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Evaluates conditions and activates the matching output branch.
 */
class ConditionNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        try {
            $config = $payload->config;
            $conditions = $config['conditions'] ?? [];
            $activeBranches = [];

            foreach ($conditions as $index => $condition) {
                if ($this->evaluate($condition, $payload->inputData)) {
                    $activeBranches[] = $condition['branch'] ?? "branch_{$index}";
                }
            }

            if (empty($activeBranches) && isset($config['default_branch'])) {
                $activeBranches[] = $config['default_branch'];
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return new NodeResult(
                status: \App\Enums\ExecutionNodeStatus::Completed,
                output: ['evaluated_branches' => $activeBranches],
                durationMs: $durationMs,
                activeBranches: $activeBranches,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed($e->getMessage(), 'CONDITION_ERROR', $durationMs);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $data
     */
    private function evaluate(array $condition, array $data): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        $actual = data_get($data, $field);

        return match ($operator) {
            'equals', '==' => $actual == $value,
            'not_equals', '!=' => $actual != $value,
            'greater_than', '>' => $actual > $value,
            'less_than', '<' => $actual < $value,
            'contains' => is_string($actual) && str_contains($actual, (string) $value),
            'not_contains' => is_string($actual) && ! str_contains($actual, (string) $value),
            'is_empty' => empty($actual),
            'is_not_empty' => ! empty($actual),
            'exists' => $actual !== null,
            'not_exists' => $actual === null,
            default => false,
        };
    }
}

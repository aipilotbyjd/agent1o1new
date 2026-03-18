<?php

namespace App\Engine\Nodes\Apps\Util;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Log;

class UtilNode extends AppNode
{
    protected function errorCode(): string
    {
        return 'UTIL_ERROR';
    }

    protected function operations(): array
    {
        return [
            'filter' => $this->filter(...),
            'aggregate' => $this->aggregate(...),
            'json_parse' => $this->jsonParse(...),
            'template' => $this->template(...),
            'logger' => $this->logger(...),
            'error_handler' => $this->errorHandler(...),
        ];
    }

    /**
     * Filter an array of items by conditions.
     *
     * @return array<string, mixed>
     */
    private function filter(NodePayload $payload): array
    {
        $items = $payload->inputData['items'] ?? [];
        $conditions = $payload->config['conditions'] ?? [];

        $filtered = array_values(array_filter($items, function (array $item) use ($conditions): bool {
            foreach ($conditions as $condition) {
                $field = $condition['field'] ?? '';
                $operator = $condition['operator'] ?? 'equals';
                $value = $condition['value'] ?? null;
                $fieldValue = $item[$field] ?? null;

                if (! $this->matchesCondition($fieldValue, $operator, $value)) {
                    return false;
                }
            }

            return true;
        }));

        return [
            'items' => $filtered,
            'count' => count($filtered),
        ];
    }

    /**
     * Evaluate a single filter condition.
     */
    private function matchesCondition(mixed $fieldValue, string $operator, mixed $value): bool
    {
        return match ($operator) {
            'equals' => $fieldValue == $value,
            'not_equals' => $fieldValue != $value,
            'contains' => is_string($fieldValue) && str_contains($fieldValue, (string) $value),
            'gt' => $fieldValue > $value,
            'lt' => $fieldValue < $value,
            default => false,
        };
    }

    /**
     * Aggregate an array of items.
     *
     * @return array<string, mixed>
     */
    private function aggregate(NodePayload $payload): array
    {
        $items = $payload->inputData['items'] ?? [];
        $operation = $payload->config['aggregate_operation'] ?? 'count';
        $field = $payload->config['field'] ?? '';

        $values = $field !== ''
            ? array_map(fn (array $item): mixed => $item[$field] ?? 0, $items)
            : $items;

        $result = match ($operation) {
            'count' => count($values),
            'sum' => array_sum($values),
            'average' => count($values) > 0 ? array_sum($values) / count($values) : 0,
            'min' => count($values) > 0 ? min($values) : 0,
            'max' => count($values) > 0 ? max($values) : 0,
            default => throw new \InvalidArgumentException("Unknown aggregate operation: {$operation}"),
        };

        return [
            'result' => $result,
        ];
    }

    /**
     * Parse a JSON string into structured data.
     *
     * @return array<string, mixed>
     */
    private function jsonParse(NodePayload $payload): array
    {
        $jsonString = $payload->inputData['json_string'] ?? '';

        $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

        return [
            'data' => $data,
        ];
    }

    /**
     * Render a template with {{variable}} placeholders.
     *
     * @return array<string, mixed>
     */
    private function template(NodePayload $payload): array
    {
        $template = $payload->config['template'] ?? '';
        $variables = $payload->inputData['variables'] ?? [];

        $text = preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($variables): string {
            return (string) ($variables[$matches[1]] ?? $matches[0]);
        }, $template);

        return [
            'text' => $text,
        ];
    }

    /**
     * Log data for debugging purposes.
     *
     * @return array<string, mixed>
     */
    private function logger(NodePayload $payload): array
    {
        $level = $payload->config['level'] ?? 'info';
        $message = $payload->inputData['message'] ?? '';
        $data = $payload->inputData['data'] ?? [];

        $allowedLevels = ['debug', 'info', 'warning', 'error'];
        if (! in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        Log::$level($message, $data);

        return [
            'logged' => true,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Pass through input data for error handling (actual logic is in the engine).
     *
     * @return array<string, mixed>
     */
    private function errorHandler(NodePayload $payload): array
    {
        return $payload->inputData;
    }
}

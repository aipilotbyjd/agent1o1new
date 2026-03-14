<?php

namespace App\Engine\Nodes\Core;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

/**
 * Transforms and reshapes data from upstream nodes.
 *
 * Supports three modes:
 *  - "mapping": key-value field mapping (expressions already resolved in inputData)
 *  - "passthrough": forwards all input data unchanged
 *  - "static": returns a fixed output defined in config
 */
class TransformNode implements NodeHandler
{
    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        try {
            $output = $this->transform($payload);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::completed($output, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed($e->getMessage(), 'TRANSFORM_ERROR', $durationMs);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(NodePayload $payload): array
    {
        $mode = $payload->config['mode'] ?? 'mapping';

        return match ($mode) {
            'passthrough' => $payload->inputData,
            'static' => $payload->config['output'] ?? [],
            default => $this->applyMapping($payload),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function applyMapping(NodePayload $payload): array
    {
        $mappings = $payload->config['mappings'] ?? [];
        $output = [];

        foreach ($mappings as $outputField => $sourceField) {
            if (is_string($sourceField)) {
                $output[$outputField] = data_get($payload->inputData, $sourceField);
            } else {
                $output[$outputField] = $sourceField;
            }
        }

        if (empty($mappings)) {
            return $payload->inputData;
        }

        return $output;
    }
}

<?php

namespace App\Engine\Data;

/**
 * Manages node outputs in memory during execution.
 *
 * Tracks which downstream nodes still need each output (ref-counting)
 * and evicts outputs when no longer referenced. Spills large outputs
 * to disk to prevent memory exhaustion.
 */
class OutputBuffer
{
    /** @var array<string, array<string, mixed>> nodeId → output data (or spill reference) */
    private array $outputs = [];

    /** @var array<string, int> nodeId → remaining downstream consumer count */
    private array $refCounts = [];

    /** @var array<string, string> nodeId → file path for spilled outputs */
    private array $spilledFiles = [];

    private int $spillThresholdBytes;

    private string $spillDirectory;

    /**
     * @param  array<string, list<string>>  $downstreamConsumers  nodeId → list of downstream node IDs that reference this output.
     */
    public function __construct(
        private readonly int $executionId,
        private readonly array $downstreamConsumers = [],
        int $spillThresholdBytes = 262_144, // 256 KB
    ) {
        $this->spillThresholdBytes = $spillThresholdBytes;
        $this->spillDirectory = storage_path("app/engine-outputs/{$this->executionId}");

        foreach ($downstreamConsumers as $nodeId => $consumers) {
            $this->refCounts[$nodeId] = count($consumers);
        }
    }

    /**
     * Store a node's output data.
     *
     * @param  array<string, mixed>|null  $output
     */
    public function store(string $nodeId, ?array $output): void
    {
        if ($output === null) {
            $this->outputs[$nodeId] = [];

            return;
        }

        $encoded = json_encode($output);
        $size = $encoded !== false ? strlen($encoded) : 0;

        if ($size > $this->spillThresholdBytes) {
            $this->spillToDisk($nodeId, $output);

            return;
        }

        $this->outputs[$nodeId] = $output;
    }

    /**
     * Retrieve a node's output data.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $nodeId): ?array
    {
        if (isset($this->spilledFiles[$nodeId])) {
            return $this->loadFromDisk($nodeId);
        }

        return $this->outputs[$nodeId] ?? null;
    }

    /**
     * Retrieve a specific path from a node's output.
     */
    public function getPath(string $nodeId, string $path): mixed
    {
        $output = $this->get($nodeId);

        if ($output === null) {
            return null;
        }

        return data_get($output, $path);
    }

    /**
     * Signal that a downstream consumer has finished using this node's output.
     * When the ref count reaches zero, the output is evicted from memory.
     */
    public function release(string $nodeId): void
    {
        if (! isset($this->refCounts[$nodeId])) {
            return;
        }

        $this->refCounts[$nodeId]--;

        if ($this->refCounts[$nodeId] <= 0) {
            $this->evict($nodeId);
        }
    }

    /**
     * Check if output data exists for a node.
     */
    public function has(string $nodeId): bool
    {
        return isset($this->outputs[$nodeId]) || isset($this->spilledFiles[$nodeId]);
    }

    /**
     * Get all stored outputs (for checkpointing).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $all = $this->outputs;

        foreach ($this->spilledFiles as $nodeId => $path) {
            $all[$nodeId] = $this->loadFromDisk($nodeId);
        }

        return $all;
    }

    /**
     * Get approximate memory usage in bytes.
     */
    public function memoryUsage(): int
    {
        $encoded = json_encode($this->outputs);

        return $encoded !== false ? strlen($encoded) : 0;
    }

    /**
     * Clean up any spilled files from disk.
     */
    public function cleanup(): void
    {
        foreach ($this->spilledFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        if (is_dir($this->spillDirectory)) {
            @rmdir($this->spillDirectory);
        }

        $this->spilledFiles = [];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function spillToDisk(string $nodeId, array $output): void
    {
        if (! is_dir($this->spillDirectory)) {
            mkdir($this->spillDirectory, 0755, true);
        }

        $filePath = "{$this->spillDirectory}/{$nodeId}.json";
        file_put_contents($filePath, json_encode($output));

        $this->spilledFiles[$nodeId] = $filePath;
        $this->outputs[$nodeId] = ['__spilled' => true, '__path' => $filePath];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFromDisk(string $nodeId): array
    {
        $path = $this->spilledFiles[$nodeId] ?? null;

        if ($path === null || ! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);

        return $content !== false ? (json_decode($content, true) ?? []) : [];
    }

    private function evict(string $nodeId): void
    {
        unset($this->outputs[$nodeId], $this->refCounts[$nodeId]);

        if (isset($this->spilledFiles[$nodeId])) {
            $path = $this->spilledFiles[$nodeId];
            if (file_exists($path)) {
                @unlink($path);
            }
            unset($this->spilledFiles[$nodeId]);
        }
    }
}

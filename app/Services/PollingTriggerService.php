<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Exceptions\ApiException;
use App\Models\PollingTrigger;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PollingTriggerService
{
    public function __construct(private ExecutionService $executionService) {}

    /**
     * Create a polling trigger for a workflow.
     *
     * @param  array{endpoint_url: string, http_method?: string, headers?: array, query_params?: array, body?: array, dedup_key: string, interval_seconds?: int, auth_config?: array}  $data
     */
    public function create(Workspace $workspace, Workflow $workflow, array $data): PollingTrigger
    {
        if ($workflow->pollingTriggers()->exists()) {
            throw ApiException::conflict('This workflow already has a polling trigger. Each workflow can only have one polling trigger.');
        }

        return PollingTrigger::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
            'endpoint_url' => $data['endpoint_url'],
            'http_method' => $data['http_method'] ?? 'GET',
            'headers' => $data['headers'] ?? null,
            'query_params' => $data['query_params'] ?? null,
            'body' => $data['body'] ?? null,
            'dedup_key' => $data['dedup_key'],
            'interval_seconds' => $data['interval_seconds'] ?? 300,
            'is_active' => true,
            'auth_config' => $data['auth_config'] ?? null,
            'next_poll_at' => now(),
        ]);
    }

    /**
     * Update a polling trigger.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PollingTrigger $pollingTrigger, array $data): PollingTrigger
    {
        $pollingTrigger->update($data);

        return $pollingTrigger;
    }

    /**
     * Delete a polling trigger.
     */
    public function delete(PollingTrigger $pollingTrigger): void
    {
        $pollingTrigger->delete();
    }

    /**
     * Poll a single trigger: fetch data, deduplicate, and trigger executions for new records.
     *
     * @return int Number of new records that triggered executions.
     */
    public function poll(PollingTrigger $pollingTrigger): int
    {
        $pollingTrigger->loadMissing(['workflow.creator', 'workspace.owner']);

        $workflow = $pollingTrigger->workflow;

        if (! $workflow || ! $workflow->is_active || ! $workflow->current_version_id) {
            return 0;
        }

        try {
            $response = $this->fetchData($pollingTrigger);
        } catch (\Throwable $e) {
            $pollingTrigger->update([
                'last_polled_at' => now(),
                'next_poll_at' => now()->addSeconds($pollingTrigger->interval_seconds),
                'last_error' => $e->getMessage(),
            ]);

            return 0;
        }

        $records = $this->extractRecords($response);
        $newRecords = $this->deduplicate($records, $pollingTrigger);

        $triggered = 0;
        $triggeredBy = $workflow->creator ?? $pollingTrigger->workspace->owner;

        foreach ($newRecords as $record) {
            try {
                $this->executionService->trigger(
                    $workflow,
                    $triggeredBy,
                    [
                        'trigger' => 'polling',
                        'polling_trigger_id' => $pollingTrigger->id,
                        'record' => $record,
                    ],
                    ExecutionMode::Polling,
                );

                $triggered++;
            } catch (\Throwable $e) {
                Log::error('Polling trigger execution failed: '.$e->getMessage(), [
                    'polling_trigger_id' => $pollingTrigger->id,
                    'workflow_id' => $workflow->id,
                ]);
            }
        }

        // Update seen IDs and stats
        $newIds = array_map(
            fn (array $record) => $this->extractDedupValue($record, $pollingTrigger->dedup_key),
            $newRecords,
        );

        $seenIds = array_unique(array_merge($pollingTrigger->last_seen_ids ?? [], $newIds));
        $seenIds = array_slice($seenIds, -1000); // Keep last 1000 IDs

        $pollingTrigger->update([
            'last_seen_ids' => array_values($seenIds),
            'last_polled_at' => now(),
            'next_poll_at' => now()->addSeconds($pollingTrigger->interval_seconds),
            'poll_count' => $pollingTrigger->poll_count + 1,
            'trigger_count' => $pollingTrigger->trigger_count + $triggered,
            'last_error' => null,
        ]);

        return $triggered;
    }

    /**
     * Fetch data from the configured endpoint.
     *
     * @return array<int, mixed>
     */
    private function fetchData(PollingTrigger $pollingTrigger): array
    {
        $request = Http::timeout(30)->withHeaders($pollingTrigger->headers ?? []);

        $authConfig = $pollingTrigger->auth_config;
        if ($authConfig) {
            $request = match ($authConfig['type'] ?? 'none') {
                'bearer' => $request->withToken($authConfig['token'] ?? ''),
                'basic' => $request->withBasicAuth($authConfig['username'] ?? '', $authConfig['password'] ?? ''),
                default => $request,
            };
        }

        $response = match ($pollingTrigger->http_method) {
            'POST' => $request->post($pollingTrigger->endpoint_url, $pollingTrigger->body ?? []),
            default => $request->get($pollingTrigger->endpoint_url, $pollingTrigger->query_params ?? []),
        };

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Extract an array of records from the API response.
     *
     * @return list<array<string, mixed>>
     */
    private function extractRecords(array $response): array
    {
        // If the response is already a list, return it
        if (array_is_list($response)) {
            return $response;
        }

        // Look for common wrapper keys
        foreach (['data', 'results', 'items', 'records', 'entries'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && array_is_list($response[$key])) {
                return $response[$key];
            }
        }

        // Single record — wrap it
        return [$response];
    }

    /**
     * Filter out records that have already been seen.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $records, PollingTrigger $pollingTrigger): array
    {
        $seenIds = $pollingTrigger->last_seen_ids ?? [];

        if (empty($seenIds)) {
            return $records;
        }

        $seenMap = array_flip($seenIds);

        return array_values(array_filter(
            $records,
            fn (array $record) => ! isset($seenMap[$this->extractDedupValue($record, $pollingTrigger->dedup_key)]),
        ));
    }

    /**
     * Extract the deduplication value from a record using a dot-notation key.
     */
    private function extractDedupValue(array $record, string $dedupKey): string
    {
        return (string) data_get($record, $dedupKey, '');
    }
}

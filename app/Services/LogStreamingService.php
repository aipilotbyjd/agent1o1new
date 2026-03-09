<?php

namespace App\Services;

use App\Models\LogStreamingConfig;
use App\Models\User;
use App\Models\Workspace;

class LogStreamingService
{
    /**
     * @param  array{name: string, destination_type: string, destination_config: array, event_types?: array, is_active?: bool, include_node_data?: bool}  $data
     */
    public function create(Workspace $workspace, User $creator, array $data): LogStreamingConfig
    {
        return LogStreamingConfig::create([
            ...$data,
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LogStreamingConfig $config, array $data): LogStreamingConfig
    {
        $config->update($data);

        return $config;
    }

    public function delete(LogStreamingConfig $config): void
    {
        $config->delete();
    }
}

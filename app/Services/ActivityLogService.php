<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityLogService
{
    /**
     * Record an activity log entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        Workspace $workspace,
        ?User $user,
        string $action,
        string $description,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): ActivityLog {
        return $workspace->activityLogs()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}

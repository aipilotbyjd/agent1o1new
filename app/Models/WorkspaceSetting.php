<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'timezone',
        'execution_retention_days',
        'default_max_retries',
        'default_timeout_seconds',
        'auto_activate_workflows',
        'allow_public_sharing',
        'notification_preferences',
        'git_sync_config',
        'git_repo_url',
        'git_branch',
        'git_auto_sync',
        'last_git_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'execution_retention_days' => 'integer',
            'default_max_retries' => 'integer',
            'default_timeout_seconds' => 'integer',
            'auto_activate_workflows' => 'boolean',
            'allow_public_sharing' => 'boolean',
            'notification_preferences' => 'array',
            'git_sync_config' => 'encrypted:array',
            'git_auto_sync' => 'boolean',
            'last_git_sync_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

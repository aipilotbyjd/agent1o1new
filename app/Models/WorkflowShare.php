<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'shared_by',
        'share_token',
        'is_public',
        'allow_clone',
        'password',
        'expires_at',
        'view_count',
        'clone_count',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'allow_clone' => 'boolean',
            'password' => 'hashed',
            'expires_at' => 'datetime',
            'view_count' => 'integer',
            'clone_count' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccessible(): bool
    {
        return $this->is_public && ! $this->isExpired();
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}

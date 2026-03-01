<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Credential extends Model
{
    /** @use HasFactory<\Database\Factories\CredentialFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'type',
        'data',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Workflow, $this>
     */
    public function workflows(): BelongsToMany
    {
        return $this->belongsToMany(Workflow::class, 'workflow_credentials')
            ->withPivot('node_id')
            ->withTimestamps();
    }
}

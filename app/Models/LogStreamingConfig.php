<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogStreamingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'destination_type',
        'destination_config',
        'event_types',
        'is_active',
        'include_node_data',
        'last_sent_at',
        'error_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'destination_config' => 'encrypted:array',
            'event_types' => 'array',
            'is_active' => 'boolean',
            'include_node_data' => 'boolean',
            'last_sent_at' => 'datetime',
            'error_count' => 'integer',
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
}

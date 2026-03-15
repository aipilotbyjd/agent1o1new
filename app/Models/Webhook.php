<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webhook extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'uuid',
        'provider',
        'external_webhook_id',
        'external_webhook_secret',
        'provider_config',
        'path',
        'methods',
        'is_active',
        'auth_type',
        'auth_config',
        'rate_limit',
        'response_mode',
        'response_status',
        'response_body',
        'call_count',
        'last_called_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'methods' => 'array',
            'is_active' => 'boolean',
            'auth_config' => 'array',
            'external_webhook_secret' => 'encrypted',
            'provider_config' => 'array',
            'response_body' => 'array',
            'last_called_at' => 'datetime',
        ];
    }

    /**
     * Check if this webhook is managed by an external provider.
     */
    public function isExternallyManaged(): bool
    {
        return $this->provider !== null && $this->external_webhook_id !== null;
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
}

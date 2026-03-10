<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthCredentialState extends Model
{
    protected $table = 'oauth_credential_states';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'credential_id',
        'credential_type',
        'state_token',
        'provider',
        'authorization_url',
        'redirect_uri',
        'scopes',
        'code_verifier',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGenerationLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'prompt',
        'generated_json',
        'model_used',
        'tokens_used',
        'confidence',
        'status',
        'workflow_id',
        'feedback',
    ];

    protected function casts(): array
    {
        return [
            'generated_json' => 'array',
            'confidence' => 'decimal:2',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}

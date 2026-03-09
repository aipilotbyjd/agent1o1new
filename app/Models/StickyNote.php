<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StickyNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'created_by',
        'content',
        'color',
        'position_x',
        'position_y',
        'width',
        'height',
        'z_index',
    ];

    protected function casts(): array
    {
        return [
            'position_x' => 'decimal:2',
            'position_y' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'z_index' => 'integer',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

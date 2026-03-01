<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialType extends Model
{
    /** @use HasFactory<\Database\Factories\CredentialTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'description',
        'icon',
        'color',
        'fields_schema',
        'test_config',
        'docs_url',
    ];

    protected function casts(): array
    {
        return [
            'fields_schema' => 'array',
            'test_config' => 'array',
        ];
    }
}

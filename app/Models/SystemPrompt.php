<?php

namespace App\Models;

use Database\Factories\SystemPromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'content',
    'version',
    'is_active',
])]
class SystemPrompt extends Model
{
    /** @use HasFactory<SystemPromptFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the active prompt for the given name.
     */
    public static function activeFor(string $name): ?self
    {
        return static::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->latest('version')
            ->first();
    }
}

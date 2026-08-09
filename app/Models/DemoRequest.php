<?php

namespace App\Models;

use Database\Factories\DemoRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'email',
    'organization',
    'message',
    'status',
    'recaptcha_score',
    'ip_address',
    'user_agent',
])]
class DemoRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    /** @use HasFactory<DemoRequestFactory> */
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
            'recaptcha_score' => 'float',
        ];
    }
}

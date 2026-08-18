<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subtask extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'todo_id',
        'title',
        'done',
        'order',
    ];

    protected static function booted(): void
    {
        static::creating(function (Subtask $model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }
}

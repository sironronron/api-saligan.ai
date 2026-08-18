<?php

namespace App\Models;

use Database\Factories\VettingMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vetting_request_id', 'author_id', 'body'])]
class VettingMessage extends Model
{
    /** @use HasFactory<VettingMessageFactory> */
    use HasFactory;

    /**
     * The request this message belongs to.
     */
    public function vettingRequest(): BelongsTo
    {
        return $this->belongsTo(VettingRequest::class);
    }

    /**
     * The user who wrote the message.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

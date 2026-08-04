<?php

namespace App\Models;

use Database\Factories\LegalChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'crawled_page_id',
    'chunk_index',
    'content',
    'embedding',
])]
class LegalChunk extends Model
{
    /** @use HasFactory<LegalChunkFactory> */
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
            'embedding' => 'array',
        ];
    }

    /**
     * The crawled page this chunk belongs to.
     */
    public function crawledPage(): BelongsTo
    {
        return $this->belongsTo(CrawledPage::class);
    }
}

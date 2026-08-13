<?php

namespace App\Models;

use App\Enums\CrawlStatus;
use Database\Factories\CrawledPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'legal_source_id',
    'url',
    'title',
    'content_hash',
    'raw_html_path',
    'law_name',
    'gr_number',
    'promulgation_date',
    'digest',
    'digest_generated_at',
    'crawl_status',
    'last_error',
    'last_crawled_at',
])]
class CrawledPage extends Model
{
    /** @use HasFactory<CrawledPageFactory> */
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
            'promulgation_date' => 'date',
            'crawl_status' => CrawlStatus::class,
            'last_crawled_at' => 'datetime',
            'digest_generated_at' => 'datetime',
        ];
    }

    /**
     * The legal source this page belongs to.
     */
    public function legalSource(): BelongsTo
    {
        return $this->belongsTo(LegalSource::class);
    }

    /**
     * The chunks extracted from this page.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(LegalChunk::class);
    }

    /**
     * Alias for the chunks relation, for consistency with other models.
     */
    public function legalChunks(): HasMany
    {
        return $this->chunks();
    }
}

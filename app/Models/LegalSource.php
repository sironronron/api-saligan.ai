<?php

namespace App\Models;

use App\Enums\LegalSourceCategory;
use Database\Factories\LegalSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'name',
    'base_domain',
    'seed_urls',
    'is_active',
    'category',
])]
class LegalSource extends Model
{
    /** @use HasFactory<LegalSourceFactory> */
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
            'seed_urls' => 'array',
            'is_active' => 'boolean',
            'category' => LegalSourceCategory::class,
        ];
    }

    /**
     * The pages crawled from this source.
     */
    public function crawledPages(): HasMany
    {
        return $this->hasMany(CrawledPage::class);
    }

    /**
     * All chunks collected from this source's crawled pages.
     */
    public function legalChunks(): HasManyThrough
    {
        return $this->hasManyThrough(LegalChunk::class, CrawledPage::class);
    }
}

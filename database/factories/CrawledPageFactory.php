<?php

namespace Database\Factories;

use App\Enums\CrawlStatus;
use App\Enums\LegalSourceCategory;
use App\Models\CrawledPage;
use App\Models\LegalSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawledPage>
 */
class CrawledPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_source_id' => LegalSource::factory(),
            'url' => fake()->url(),
            'content_hash' => fake()->sha256(),
            'crawl_status' => CrawlStatus::Ok,
            'kind' => CrawledPage::KIND_CRAWLED,
            'category' => LegalSourceCategory::Law,
            'law_name' => 'RA No. 1234',
            'gr_number' => 'G.R. No. '.fake()->numberBetween(100000, 200000),
            'promulgation_date' => fake()->date(),
            'last_crawled_at' => now(),
        ];
    }

    /**
     * An admin-uploaded legal document rather than a crawled page.
     */
    public function uploaded(): static
    {
        return $this->state(fn () => [
            'legal_source_id' => null,
            'url' => null,
            'kind' => CrawledPage::KIND_UPLOADED,
            'original_filename' => 'uploaded-doc.pdf',
            'mime_type' => 'application/pdf',
            'crawl_status' => CrawlStatus::Ok,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Enums\LegalSourceCategory;
use App\Models\LegalSource;
use Illuminate\Database\Seeder;

class LegalSourceSeeder extends Seeder
{
    /**
     * Seed the allowlist of official Philippine legal sources to crawl.
     */
    public function run(): void
    {
        $sources = [
            [
                'name' => 'Supreme Court E-Library',
                'base_domain' => 'elibrary.judiciary.gov.ph',
                'seed_urls' => ['https://elibrary.judiciary.gov.ph/thebookshelf/showdocs'],
                'category' => LegalSourceCategory::Jurisprudence,
            ],
            [
                'name' => 'LawPhil',
                'base_domain' => 'lawphil.net',
                'seed_urls' => [
                    'https://lawphil.net/statutes/repacts/repacts.html',
                    'https://lawphil.net/judjuris/judjuris.html',
                ],
                'category' => LegalSourceCategory::Law,
            ],
            [
                'name' => 'Official Gazette',
                'base_domain' => 'officialgazette.gov.ph',
                'seed_urls' => ['https://www.officialgazette.gov.ph/laws/'],
                'category' => LegalSourceCategory::Law,
            ],
            [
                'name' => 'Land Registration Authority',
                'base_domain' => 'lra.gov.ph',
                'seed_urls' => ['https://www.lra.gov.ph/legal-issuances'],
                'category' => LegalSourceCategory::Issuance,
            ],
            [
                'name' => 'Department of Agrarian Reform',
                'base_domain' => 'dar.gov.ph',
                'seed_urls' => ['https://www.dar.gov.ph/legal-issuances'],
                'category' => LegalSourceCategory::Issuance,
            ],
            [
                'name' => 'Supreme Court Website',
                'base_domain' => 'sc.judiciary.gov.ph',
                'seed_urls' => ['https://sc.judiciary.gov.ph/important-judgments/'],
                'category' => LegalSourceCategory::Jurisprudence,
            ],
        ];

        foreach ($sources as $source) {
            LegalSource::updateOrCreate(
                ['base_domain' => $source['base_domain']],
                $source,
            );
        }
    }
}

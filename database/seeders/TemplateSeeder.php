<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Support\LegalTemplateLibrary;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Seed the system template library, mirroring the legal template library
     * declared in resources/legal_templates/manifest.json.
     */
    public function run(): void
    {
        $library = LegalTemplateLibrary::all();

        $documentTypes = [];

        foreach ($library as $template) {
            $documentType = (string) ($template['document_type'] ?? '');

            if ($documentType === '') {
                continue;
            }

            $documentTypes[] = $documentType;

            Template::updateOrCreate(
                ['category' => 'legal', 'legal_subtype' => $documentType, 'user_id' => null],
                $this->systemTemplate($template),
            );
        }

        Template::query()
            ->whereNull('user_id')
            ->whereNotNull('legal_subtype')
            ->whereNotIn('legal_subtype', $documentTypes)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    protected function systemTemplate(array $template): array
    {
        return [
            'name' => LegalTemplateLibrary::title($template),
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => $template['document_type'] ?? null,
            'structure' => [],
            'placeholder_fields' => array_map(
                fn (array $field): array => [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'required' => $field['required'],
                ],
                LegalTemplateLibrary::intakeFields($template),
            ),
            'default_for_case_types' => [],
            'content' => LegalTemplateLibrary::body($template),
            'user_id' => null,
        ];
    }
}

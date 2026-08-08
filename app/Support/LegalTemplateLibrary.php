<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class LegalTemplateLibrary
{
    /**
     * In-memory per-request cache of the resolved library.
     *
     * @var array<string, array<int, array<string, mixed>>>|null
     */
    protected static ?array $loaded = null;

    /**
     * Keyword → document_type map for resolving a drafting request to a
     * library template. Keys are normalized (lowercased, non-alphanumeric
     * stripped) and matched longest-first so the most specific phrase wins
     * (e.g. "special power of attorney" before "power of attorney").
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected static array $keywords = [
        ['specialpowerofattorney', 'special_power_of_attorney'],
        ['secretaryscertificate', 'board_resolution'],
        ['secretarycertificate', 'board_resolution'],
        ['boardresolution', 'board_resolution'],
        ['certificationagainstforumshopping', 'verification_and_certification'],
        ['forumshopping', 'verification_and_certification'],
        ['verificationandcertification', 'verification_and_certification'],
        ['verification', 'verification_and_certification'],
        ['deedofabsolutesale', 'deed_of_absolute_sale'],
        ['deedofsale', 'deed_of_absolute_sale'],
        ['absolutesale', 'deed_of_absolute_sale'],
        ['deedofsalewith', 'deed_of_absolute_sale'],
        ['kasulatan', 'deed_of_absolute_sale'],
        ['powerofattorney', 'special_power_of_attorney'],
        ['attorneyinfact', 'special_power_of_attorney'],
        ['poa', 'special_power_of_attorney'],
        ['motionforextension', 'motion_for_extension_of_time'],
        ['extensionoftime', 'motion_for_extension_of_time'],
        ['motionforextensionoftime', 'motion_for_extension_of_time'],
        ['nondisclosureagreement', 'non_disclosure_agreement'],
        ['nondisclosure', 'non_disclosure_agreement'],
        ['confidentialityagreement', 'non_disclosure_agreement'],
        ['nda', 'non_disclosure_agreement'],
        ['serviceagreement', 'service_agreement'],
        ['contractofservice', 'service_agreement'],
        ['leaseagreement', 'lease_agreement'],
        ['contractoflease', 'lease_agreement'],
        ['demandletter', 'demand_letter'],
        ['demandforpayment', 'demand_letter'],
        ['affidavit', 'affidavit_general'],
        ['sinumpaan', 'affidavit_general'],
        ['swornstatement', 'affidavit_general'],
        ['complaint', 'complaint'],
        ['reklamo', 'complaint'],
        ['reklamasyon', 'complaint'],
        ['petition', 'complaint'],
        ['answer', 'answer'],
        ['responsivepleading', 'answer'],
    ];

    /**
     * All library templates with their frontmatter resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $manifest = self::manifest();

        if ($manifest === null) {
            return self::$loaded = [];
        }

        $templates = [];

        foreach ($manifest['templates'] ?? [] as $entry) {
            $file = $entry['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            $path = self::basePath().'/'.$file;

            if (! is_file($path)) {
                continue;
            }

            $parsed = self::parse(File::get($path));

            if ($parsed === null) {
                continue;
            }

            $templates[] = array_merge([
                'document_type' => $entry['document_type'] ?? null,
                'category' => $entry['category'] ?? null,
                'requires_lawyer_review' => (bool) ($entry['requires_lawyer_review'] ?? true),
                'requires_notarization' => (bool) ($entry['requires_notarization'] ?? false),
                'notarization_type' => $entry['notarization_type'] ?? null,
                'required_attachments' => $entry['required_attachments'] ?? [],
                'file' => $file,
                'title' => $parsed['title'],
                'body' => $parsed['body'],
            ], $parsed['frontmatter']);
        }

        return self::$loaded = $templates;
    }

    /**
     * Resolve the library template a drafting request calls for, if any.
     *
     * A `[Template: <token>]` directive takes priority, then the document
     * category the model passed with an intake tool call, then keyword
     * matching against the user's message.
     *
     * @param  string|null  $documentType  Category the model named for the
     *                                     document, if known.
     * @return array<string, mixed>|null
     */
    public static function resolveForMessage(string $message, ?string $documentType = null): ?array
    {
        $templates = self::all();

        if ($templates === []) {
            return null;
        }

        [$directive] = DraftingIntent::extractTemplateDirective($message);

        if ($directive !== null) {
            $directive = Str::startsWith($directive, 'library:')
                ? Str::after($directive, 'library:')
                : $directive;

            foreach ($templates as $template) {
                if (self::normalize((string) ($template['document_type'] ?? '')) === self::normalize($directive)) {
                    return $template;
                }
            }
        }

        if (filled($documentType)) {
            foreach ($templates as $template) {
                if (self::matchesDocumentType($template, $documentType)) {
                    return $template;
                }
            }
        }

        $needle = self::normalize($message);

        foreach (self::keywordPairs() as [$keyword, $documentTypeKey]) {
            if (str_contains($needle, $keyword)) {
                foreach ($templates as $template) {
                    if (($template['document_type'] ?? null) === $documentTypeKey) {
                        return $template;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve the library template that represents an exact document type,
     * with no keyword fallback. Used when a specific template was selected so
     * an unrelated keyword match can never hijack the selection. Synonym types
     * (e.g. "affidavit" for affidavit_general) are honored via the keyword map.
     */
    public static function forDocumentType(string $documentType): ?array
    {
        if (trim($documentType) === '') {
            return null;
        }

        $templates = self::all();
        $needle = self::normalize($documentType);

        foreach ($templates as $template) {
            if (self::normalize((string) ($template['document_type'] ?? '')) === $needle) {
                return $template;
            }
        }

        foreach (self::keywordPairs() as [$keyword, $type]) {
            if ($needle === $keyword) {
                foreach ($templates as $template) {
                    if (($template['document_type'] ?? null) === $type) {
                        return $template;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The drafting body of a template (frontmatter stripped), ready for the
     * prompt.
     */
    public static function body(array $template): string
    {
        return trim((string) ($template['body'] ?? ''));
    }

    /**
     * The shared Philippine drafting conventions document, applied to every
     * library draft.
     */
    public static function conventions(): string
    {
        $manifest = self::manifest();

        $file = $manifest['conventions_file'] ?? null;
        $path = is_string($file) ? self::basePath().'/'.$file : null;

        if ($path !== null && is_file($path)) {
            return trim(File::get($path));
        }

        $fallback = self::basePath().'/conventions/philippine_drafting_conventions.md';

        return is_file($fallback) ? trim(File::get($fallback)) : '';
    }

    /**
     * Intake form fields derived from a library template's required fields
     * and `{{PLACEHOLDER}}` tokens, so the form collects exactly what the
     * document needs. Placeholders that match the canonical fact registry
     * collapse onto its key, label, and type.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool}>
     */
    public static function intakeFields(array $template): array
    {
        $fields = [];
        $seen = [];

        foreach (self::frontmatterFields($template) as $key => $label) {
            self::collectField($fields, $seen, $key, $label, required: true);
        }

        foreach (self::placeholderFields(self::body($template)) as $key => $label) {
            self::collectField($fields, $seen, $key, $label, required: false);
        }

        return array_values($fields);
    }

    /**
     * Picker options for the library templates, shaped like the template rows
     * served by the template API so the frontend picker can render them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pickerOptions(): array
    {
        return array_map(
            fn (array $template): array => [
                'id' => 'library:'.($template['document_type'] ?? 'custom'),
                'name' => $template['title'] ?? ucwords(str_replace('_', ' ', (string) ($template['document_type'] ?? 'custom'))),
                'category' => 'legal',
                'jurisdiction' => 'PH',
                'legal_subtype' => $template['document_type'] ?? null,
                'structure' => [],
                'placeholder_fields' => array_map(
                    fn (array $field): array => ['key' => $field['key'], 'label' => $field['label'], 'required' => $field['required']],
                    self::intakeFields($template),
                ),
                'default_for_case_types' => [],
                'is_system' => true,
            ],
            self::all(),
        );
    }

    /**
     * Human-readable title for a template: the first heading in the body, the
     * frontmatter document_type, or the file name.
     */
    public static function title(array $template): string
    {
        if (isset($template['title']) && filled($template['title'])) {
            return (string) $template['title'];
        }

        $body = self::body($template);

        if (preg_match('/^#\s+(.+)$/m', $body, $matches) === 1 && filled($matches[1])) {
            return trim($matches[1]);
        }

        return ucwords(str_replace('_', ' ', (string) ($template['document_type'] ?? 'Legal document')));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function manifest(): ?array
    {
        $path = self::basePath().'/manifest.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Parse a template file into frontmatter, title, and body.
     *
     * @return array{title: string, frontmatter: array<string, mixed>, body: string}|null
     */
    protected static function parse(string $content): ?array
    {
        if (preg_match('/\A---\R(.*?)\R?---\R?(.*)\z/s', $content, $matches) !== 1) {
            return null;
        }

        try {
            $frontmatter = Yaml::parse($matches[1]);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($frontmatter)) {
            $frontmatter = [];
        }

        $body = trim($matches[2] ?? '');

        $title = '';

        if (preg_match('/^#\s+(.+)$/m', $body, $titleMatch) === 1) {
            $title = trim($titleMatch[1]);
        }

        return [
            'title' => $title,
            'frontmatter' => $frontmatter,
            'body' => $body,
        ];
    }

    /**
     * Whether a template's document type matches a natural-language document
     * category (e.g. "demand letter", "complaint with verification").
     */
    protected static function matchesDocumentType(array $template, string $documentType): bool
    {
        $needle = self::normalize($documentType);

        if ($needle === '') {
            return false;
        }

        $type = self::normalize((string) ($template['document_type'] ?? ''));

        return $type !== '' && str_contains($needle, $type);
    }

    /**
     * Required and optional field keys from the frontmatter, keyed by their
     * human-readable labels.
     *
     * @return array<string, string>
     */
    protected static function frontmatterFields(array $template): array
    {
        $fields = [];

        foreach (['required_fields', 'optional_fields'] as $section) {
            foreach (($template[$section] ?? []) as $field) {
                $label = (string) $field;

                if ($label === '') {
                    continue;
                }

                $fields[$label] = $label;
            }
        }

        return $fields;
    }

    /**
     * Unique `{{PLACEHOLDER}}` tokens from the template body, keyed by their
     * resolved key. Token text before any comma (e.g. "POWER_1, e.g. ...")
     * names the field; canonical synonyms collapse onto the fact registry.
     *
     * @return array<string, string>
     */
    protected static function placeholderFields(string $body): array
    {
        preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $body, $matches);

        $fields = [];

        foreach ($matches[1] ?? [] as $raw) {
            $name = trim(explode(',', $raw, 2)[0]);

            if ($name === '') {
                continue;
            }

            $key = DraftingIntent::canonicalForKey($name) ?? Str::slug($name, '_', 'en');

            if ($key === '' || isset($fields[$key])) {
                continue;
            }

            $fields[$key] = DraftingIntent::canonicalLabelOf($name);
        }

        return $fields;
    }

    /**
     * Add a field to the collected set, deduplicating by canonical key.
     *
     * @param  array<int, array{key: string, label: string, type: string, required: bool}>  $fields
     * @param  array<string, bool>  $seen
     */
    protected static function collectField(array &$fields, array &$seen, string $key, string $label, bool $required): void
    {
        $canonical = DraftingIntent::canonicalForKey($key) ?? $key;

        if (isset($seen[$canonical])) {
            return;
        }

        $seen[$canonical] = true;

        $fields[] = [
            'key' => $canonical,
            'label' => $label,
            'type' => self::fieldType($canonical),
            'required' => $required,
        ];
    }

    /**
     * Infer the intake input type from a resolved field key.
     */
    protected static function fieldType(string $key): string
    {
        $needle = strtolower($key);

        if (str_contains($needle, 'date') || str_contains($needle, 'deadline')) {
            return 'date';
        }

        if (str_contains($needle, 'amount') || str_contains($needle, 'consideration')
            || str_contains($needle, 'number') || str_contains($needle, 'rent')) {
            return 'text';
        }

        if (str_contains($needle, 'facts') || str_contains($needle, 'description')
            || str_contains($needle, 'statement') || str_contains($needle, 'obligations')
            || str_contains($needle, 'details') || str_contains($needle, 'narrative')) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected static function keywordPairs(): array
    {
        $pairs = self::$keywords;

        usort($pairs, fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return $pairs;
    }

    protected static function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    protected static function basePath(): string
    {
        return resource_path('legal_templates');
    }
}

<?php

namespace App\Services\Templates;

use RuntimeException;
use ZipArchive;

/**
 * Detects [Bracketed Text] placeholders in a template's extracted text and
 * verifies each one is a clean, matchable token in the original .docx's XML.
 * The bracketed literal is kept in the placeholder list because fill-in
 * matches on the literal token, never on a stripped or normalized form.
 */
class TemplatePlaceholderService
{
    public function __construct(
        private readonly DocxTemplateFiller $filler,
    ) {
        //
    }

    /**
     * The unique [Bracketed Text] tokens found in the given text, brackets
     * kept and in order of first appearance.
     *
     * @return array<int, string>
     */
    public function detect(string $text): array
    {
        preg_match_all('/\[[^\]]+\]/', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * Whether every detected token appears as a matchable literal inside one
     * of the docx's merged run groups. Returns the tokens the filler would
     * silently miss, so the caller can reject the template at upload time.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    public function unMatchable(string $docxPath, array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $groupTexts = $this->filler->groupTexts($this->documentXml($docxPath));

        $unmatchable = [];

        foreach ($tokens as $token) {
            $matchable = false;

            foreach ($groupTexts as $groupText) {
                if (str_contains($groupText, $token)) {
                    $matchable = true;

                    break;
                }
            }

            if (! $matchable) {
                $unmatchable[] = $token;
            }
        }

        return $unmatchable;
    }

    /**
     * Read the document.xml body of a .docx file.
     */
    protected function documentXml(string $storagePath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($storagePath) !== true) {
            throw new RuntimeException('Could not open the template archive.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('The template has no document body.');
        }

        return $xml;
    }
}

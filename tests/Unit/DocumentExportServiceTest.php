<?php

use App\Services\Export\DocumentExportService;

/**
 * Extract the visible text runs from a generated Word file.
 *
 * @return array<int, string>
 */
function wordTextRuns(string $docx): array
{
    $zip = new ZipArchive;
    $zip->open($docx);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $xml, $matches);

    return $matches[1];
}

it('extracts only the marked document from a drafted reply', function () {
    $content = <<<'CONTENT'
Here is your draft.

[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Very truly yours,
[[DOCUMENT_END]]

[Download as Word](/api/messages/abc/export/word)
[Download as PDF](/api/messages/abc/export/pdf)
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,");
});

it('returns the full content when no markers are present', function () {
    $service = new DocumentExportService;

    expect($service->extractDocument('A plain answer with no markers.'))
        ->toBe('A plain answer with no markers.');
});

it('renders the marked document content into the Word file', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nCOMPLAINT FOR UNLAWFUL DETAINER\n- Item one\n1. Numbered item\n[[DOCUMENT_END]]",
        'Test',
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('COMPLAINT FOR UNLAWFUL DETAINER')
        ->and($texts)->toContain('Item one')
        ->and($texts)->toContain('Numbered item');
});

it('keeps the document intact when there is chat content around the markers', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
AFFIDAVIT OF LOSS
I, Juan Dela Cruz, depose and state…
[[DOCUMENT_END]]
This is extra chat text that must be excluded.
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("AFFIDAVIT OF LOSS\nI, Juan Dela Cruz, depose and state…");
});

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

it('extracts a draft missing the closing marker from the opening marker onward', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Very truly yours,

[Download as Word](/api/messages/abc/export/word)
[Download as PDF](/api/messages/abc/export/pdf)
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,");
});

it('excludes the next steps todo checklist from the exported body', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Very truly yours,
[[DOCUMENT_END]]

Next Steps:
[[TODO_START]]
1. File the demand letter
2. Pay the filing fees
[[TODO_END]]

[Download as Word](/api/messages/abc/export/word)
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,");
});

it('drops the hidden todo markers and meta commentary from the exported body', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Next Steps Checklist Created Below Using create_todo Tool:
[[TODO_START]]
1. File the demand letter
2. Pay the filing fees
[[TODO_END]]
[[DOCUMENT_END]]
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER");
});

it('cuts a draft missing the closing marker at the todo checklist', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Very truly yours,

Next Steps:
[[TODO_START]]
1. Serve the demand letter
2. Wait for the deadline to comply
[[TODO_END]]

[Download as Word](/api/messages/abc/export/word)
[Download as PDF](/api/messages/abc/export/pdf)
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,");
});

it('keeps the todo checklist out of generated Word files', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\nVery truly yours,\n[[DOCUMENT_END]]\n\nNext Steps:\n[[TODO_START]]\n1. Serve the letter\n[[TODO_END]]",
        'Test',
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('DEMAND LETTER')
        ->and($texts)->toContain('Very truly yours,')
        ->and($texts)->not->toContain('Serve the letter')
        ->and($texts)->not->toContain('Next Steps:');
});

it('strips a bare next-steps checklist that lacks the todo markers', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Very truly yours,

**Next Steps:**
1. File the demand letter
2. Pay the filing fees
[[DOCUMENT_END]]
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,");
});

it('strips the preamble paragraph that precedes the document markers', function () {
    $content = <<<'CONTENT'
Based on the documents provided and your specific requirements, here is a formal Demand Letter addressed to the Department of Public Works and Highways regarding the acquisition of your land for the National Road Widening Project.

[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
[[DOCUMENT_END]]
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER");
});

it('replaces date placeholders in the letter with today date', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Date: [Date]
Email: [Email Address]
Contact Number: [Contact Number]
[[DOCUMENT_END]]
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nDate: ".now()->format('F j, Y'));
});

it('renders asterisk bullets as list items in the Word file', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\n*Item one\n*Item two\n[[DOCUMENT_END]]",
        'Test',
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('Item one')
        ->and($texts)->toContain('Item two');
});

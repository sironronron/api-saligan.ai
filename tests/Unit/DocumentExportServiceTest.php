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

    return array_map(
        fn (string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'),
        $matches[1],
    );
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

it('excludes a todo checklist wrapped in bold markers from the exported body', function () {
    $content = <<<'CONTENT'
[[DOCUMENT_START]]
REPUBLIC OF THE PHILIPPINES
DEMAND LETTER
Very truly yours,
[[DOCUMENT_END]]

**[TODO_START]**
- File the demand letter
- Pay the filing fees
-[TODO_END]

[Download as Word](/api/messages/abc/export/word)
CONTENT;

    $service = new DocumentExportService;

    expect($service->extractDocument($content))
        ->toBe("REPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,");
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

it('renders blockquotes as indented italic text in the Word file', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\n> \"RA No. 6657, Sec. 2 — Official Gazette\" [Link](https://example.com)\n[[DOCUMENT_END]]",
        'Test',
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('"RA No. 6657, Sec. 2 — Official Gazette" Link (https://example.com)');
});

it('renders markdown links as text with URL in the Word file', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\nSee [Source](https://example.com) for details.\n[[DOCUMENT_END]]",
        'Test',
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('See Source (https://example.com) for details.');
});

it('escapes XML special characters so the Word file stays well-formed', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\nSmith & Wesson <Law> Offices\n[[DOCUMENT_END]]",
        'Test',
    );

    $zip = new ZipArchive;
    $zip->open($file);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    $doc = new DOMDocument;
    $doc->loadXML($xml);

    expect($doc)->toBeInstanceOf(DOMDocument::class)
        ->and(wordTextRuns($file))->toContain('Smith & Wesson <Law> Offices');
});

it('strips inline citation tokens from the exported body', function () {
    $service = new DocumentExportService;

    $content = <<<'CONTENT'
[[DOCUMENT_START]]
DEMAND LETTER
Pursuant to RA 6657 [SRC K3F9] we demand payment.
The contract [DOC 197M] is attached.
This follows the ruling [DOC 197M][SRC K3F9].
Sources: [Web 1]
[[DOCUMENT_END]]
CONTENT;

    expect($service->extractDocument($content))
        ->toBe("DEMAND LETTER\nPursuant to RA 6657 we demand payment.\nThe contract is attached.\nThis follows the ruling.\nSources:");
});

it('strips legacy citation markers from the exported body', function () {
    $service = new DocumentExportService;

    $content = <<<'CONTENT'
[[DOCUMENT_START]]
DEMAND LETTER
See [Source 2] and [User Doc 3] for details.
[[DOCUMENT_END]]
CONTENT;

    expect($service->extractDocument($content))
        ->toBe("DEMAND LETTER\nSee and for details.");
});

it('keeps citation tokens out of generated Word files', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\nWe rely on the deed [DOC 197M][SRC K3F9].\n[[DOCUMENT_END]]",
        'Test',
    );

    $texts = implode(' ', wordTextRuns($file));

    expect($texts)->toContain('We rely on the deed.')
        ->and($texts)->not->toContain('[DOC')
        ->and($texts)->not->toContain('[SRC');
});

it('appends cited documents as annexes to the exported PDF', function () {
    $service = new DocumentExportService;

    $html = $service->toPdfHtml(
        "[[DOCUMENT_START]]\nDEMAND LETTER\n[[DOCUMENT_END]]",
        'DEMAND LETTER',
        [
            ['title' => 'delivery-agreement.pdf', 'content' => "The parties agree to deliver the crop by December 1.\nSigned copies are retained by both parties."],
        ],
    );

    expect($html)
        ->toContain('DEMAND LETTER')
        ->toContain('ANNEX A')
        ->toContain('delivery-agreement.pdf')
        ->toContain('The parties agree to deliver the crop by December 1.')
        ->toContain('Signed copies are retained by both parties.');
});

it('appends cited documents as annexes to the exported Word file', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\n[[DOCUMENT_END]]",
        'Test',
        [
            ['title' => 'delivery-agreement.pdf', 'content' => 'The parties agree to deliver the crop by December 1.'],
        ],
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('DEMAND LETTER')
        ->and($texts)->toContain('ANNEX A — delivery-agreement.pdf')
        ->and($texts)->toContain('The parties agree to deliver the crop by December 1.');
});

it('leaves the document unchanged when no annexes are supplied', function () {
    $service = new DocumentExportService;

    $file = $service->toWord(
        "[[DOCUMENT_START]]\nDEMAND LETTER\n[[DOCUMENT_END]]",
        'Test',
        [
            ['title' => 'empty.pdf', 'content' => "  \n"],
        ],
    );

    $texts = wordTextRuns($file);

    expect($texts)->toContain('DEMAND LETTER')
        ->and($texts)->not->toContain('empty.pdf')
        ->and($texts)->not->toContain('ANNEX');
});

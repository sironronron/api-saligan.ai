<?php

use App\Services\Documents\TextExtractor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Extraction feeds the citation reader, which now renders a source natively
 * rather than as a wall of characters. These cover the structure that has to
 * survive for that to be true.
 */
function docxFixture(callable $build): string
{
    $word = new PhpWord;
    $word->addTitleStyle(1, ['bold' => true]);
    $section = $word->addSection();

    $build($section);

    $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return $path;
}

function extractDocx(string $path): string
{
    return app(TextExtractor::class)->extractMarkdown(
        $path,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );
}

it('carries a docx heading across as a markdown heading', function () {
    $path = docxFixture(fn ($section) => $section->addTitle('People v. Dela Cruz', 1));

    expect(extractDocx($path))->toContain('# People v. Dela Cruz');

    unlink($path);
});

it('keeps bold runs and the spaces around them', function () {
    $path = docxFixture(function ($section) {
        $run = $section->addTextRun();
        $run->addText('The accused was charged with ');
        $run->addText('estafa', ['bold' => true]);
        $run->addText(' under Article 315.');
    });

    // The space either side of the emphasis is the whole point: a run's
    // trailing space lives at the edge of the run, and trimming it welds the
    // words together.
    expect(extractDocx($path))->toContain('charged with **estafa** under Article 315.');

    unlink($path);
});

it('renders consecutive list items as one tight list', function () {
    $path = docxFixture(function ($section) {
        $section->addListItem('First element: deceit', 0);
        $section->addListItem('Second element: damage', 0);
    });

    expect(extractDocx($path))->toContain("- First element: deceit\n- Second element: damage");

    unlink($path);
});

it('carries a docx table across as a markdown table', function () {
    $path = docxFixture(function ($section) {
        $table = $section->addTable();
        $row = $table->addRow();
        $row->addCell()->addText('Party');
        $row->addCell()->addText('Role');
        $row = $table->addRow();
        $row->addCell()->addText('Dela Cruz');
        $row->addCell()->addText('Accused');
    });

    $markdown = extractDocx($path);

    expect($markdown)->toContain('| Party | Role |')
        ->and($markdown)->toContain('| --- | --- |')
        ->and($markdown)->toContain('| Dela Cruz | Accused |');

    unlink($path);
});

it('infers headings and enumerations in unstructured text', function () {
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, <<<'TEXT'
    ARTICLE V
    TRANSITORY PROVISIONS

    Section 12 of the Act is hereby amended to read as follows.

    (a) The first condition applies.
    (b) The second condition applies.
    TEXT);

    $markdown = app(TextExtractor::class)->extractMarkdown($path, 'text/plain');

    expect($markdown)->toContain('## ARTICLE V')
        ->and($markdown)->toContain('- (a) The first condition applies.')
        // An ordinary sentence must never be promoted to a heading: inventing
        // structure an authority does not have is worse than flattening it.
        ->and($markdown)->not->toContain('## Section 12 of the Act');

    unlink($path);
});

it('leaves a file that is already markdown exactly as it is', function () {
    $path = tempnam(sys_get_temp_dir(), 'md');
    $source = "# Heading\n\nA paragraph with **bold** text.\n\n- one\n- two\n";
    file_put_contents($path, $source);

    expect(app(TextExtractor::class)->extractMarkdown($path, 'text/markdown'))->toBe($source);

    unlink($path);
});

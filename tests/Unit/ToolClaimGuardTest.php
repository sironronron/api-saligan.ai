<?php

use App\Support\ToolClaimGuard;
use App\Support\ToolRunLog;

function kinds(array $notices): array
{
    return array_column($notices, 'kind');
}

it('flags a reply that says it searched the web on a turn with no search', function () {
    $notices = ToolClaimGuard::inspect(
        'I searched the web and confirmed that RA 6657 Section 6 remains in force.',
        new ToolRunLog,
    );

    expect(kinds($notices))->toContain('web_search');
});

it('accepts the same claim when the search actually ran', function () {
    $runs = new ToolRunLog;
    $runs->recordResult('web_search');

    $notices = ToolClaimGuard::inspect(
        'I searched the web and confirmed that RA 6657 Section 6 remains in force.',
        $runs,
        webCitations: 2,
    );

    expect(kinds($notices))->not->toContain('web_search');
});

it('accepts the claim when a completed search came back empty', function () {
    // The check happened and returned nothing, which is exactly what the tool
    // instructs the model to report. Warning here would contradict the reply.
    $runs = new ToolRunLog;
    $runs->recordResult('web_search');

    $notices = ToolClaimGuard::inspect(
        'I checked the web and found nothing on this point.',
        $runs,
        webCitations: 0,
    );

    expect(kinds($notices))->not->toContain('web_search');
});

it('does not read advice to the user as a claim about itself', function () {
    $notices = ToolClaimGuard::inspect(
        'You can search the web for the latest DAR administrative order before you file.',
        new ToolRunLog,
    );

    expect($notices)->toBe([]);
});

it('flags a web marker numbered past the sources that came back', function () {
    $runs = new ToolRunLog;
    $runs->recordResult('web_search');

    $notices = ToolClaimGuard::inspect(
        'The period is fifteen days [Web 4].',
        $runs,
        webCitations: 2,
    );

    expect(kinds($notices))->toContain('web_markers');
});

it('leaves web markers within range alone', function () {
    $runs = new ToolRunLog;
    $runs->recordResult('web_search');

    $notices = ToolClaimGuard::inspect(
        'The period is fifteen days [Web 2].',
        $runs,
        webCitations: 2,
    );

    expect($notices)->toBe([]);
});

it('flags tasks the reply says it saved when nothing was written', function () {
    $notices = ToolClaimGuard::inspect(
        "I've added the following tasks to your task list so you can track the deadline.",
        new ToolRunLog,
    );

    expect(kinds($notices))->toContain('todo');
});

it('accepts the task claim when the text fallback wrote them', function () {
    $notices = ToolClaimGuard::inspect(
        "I've added the following tasks to your task list.",
        new ToolRunLog,
        todosRecovered: true,
    );

    expect(kinds($notices))->toBe([]);
});

it('flags a letter the reply says it opened in the editor', function () {
    $notices = ToolClaimGuard::inspect(
        "I've drafted the demand letter into the editor on the right.",
        new ToolRunLog,
    );

    expect(kinds($notices))->toContain('letter');
});

it('flags claims the product can never satisfy, whatever ran', function () {
    $runs = new ToolRunLog;
    $runs->recordResult('web_search');
    $runs->recordResult('create_todo');

    $notices = ToolClaimGuard::inspect(
        'I have emailed the notice to your client and filed the petition with the Register of Deeds.',
        $runs,
        webCitations: 3,
        todosRecovered: true,
    );

    expect(kinds($notices))->toContain('email')->toContain('filing');
});

it('reports nothing for a reply that makes no claims about itself', function () {
    $notices = ToolClaimGuard::inspect(
        'Under Article 1673 of the Civil Code the lessor may judicially eject the lessee.',
        new ToolRunLog,
    );

    expect($notices)->toBe([]);
});

it('reports nothing for an empty reply', function () {
    expect(ToolClaimGuard::inspect('   ', new ToolRunLog))->toBe([]);
});

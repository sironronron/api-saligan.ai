<?php

use App\Support\AdvisoryParser;

it('recovers the caveats a reply wrote as prose', function () {
    $reply = <<<'TEXT'
1. Direct answer: The sale is voidable.

2. Legal basis: RA 6657, Sec. 27 [SRC K3F9].

Caveats and next steps:
- The date of receipt of the demand letter is unconfirmed, so the 15-day period cannot be computed.
- The lot may still be within the ten-year alienation ban under the CLOA.

Sources
> "RA No. 6657, Sec. 27" [Link](https://example.gov.ph)
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(2)
        ->and($items[0]['title'])->toContain('date of receipt')
        ->and($items[1]['title'])->toContain('alienation ban')
        // The Sources section closes it — no source line leaks in as a caveat.
        ->and(collect($items)->pluck('title')->implode(' '))->not->toContain('RA No. 6657, Sec. 27"');
});

it('recognizes the section under its other headings', function () {
    foreach (['## Caveats', '**Limitations:**', '4. Caveats and next steps', 'Things to watch out for:'] as $heading) {
        expect(AdvisoryParser::hasSection("{$heading}\n- The tenancy status of the occupant was never established"))
            ->toBeTrue("heading not recognized: {$heading}");
    }
});

it('finds no section in a reply that has none', function () {
    $reply = "1. Direct answer: Yes.\n\nSources\n> \"RA No. 386, Art. 1191\"";

    expect(AdvisoryParser::hasSection($reply))->toBeFalse()
        ->and(AdvisoryParser::fromReply($reply))->toBe([]);
});

it('does not treat the next steps checklist as caveats', function () {
    // Next steps are tasks and belong to create_todo; picking them up here
    // would file every action item twice, once as a task and once as a caveat.
    $reply = <<<'TEXT'
Next Steps
- File the complaint with the RTC
- Pay the filing fees
TEXT;

    expect(AdvisoryParser::hasSection($reply))->toBeFalse();
});

it('stops at the next steps checklist that follows a caveats section', function () {
    $reply = <<<'TEXT'
Caveats:
- The property boundaries in the tax declaration do not match the TCT

Next Steps
- File the complaint with the RTC
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toContain('boundaries');
});

it('ignores free prose inside the section', function () {
    // A wrapped sentence would arrive as two truncated half-caveats, and half a
    // caveat shown as a real one is worse than none.
    $reply = <<<'TEXT'
Caveats:
This answer assumes several things about the transaction that were never
stated in your message or documents.
- The buyer's civil status is unstated, which affects the conjugal-consent requirement
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toContain('civil status');
});

it('never mines a drafted document body', function () {
    $reply = <<<'TEXT'
Caveats:
[[DOCUMENT_START]]
- WHEREAS, the parties agree to the following terms and conditions
[[DOCUMENT_END]]
TEXT;

    expect(AdvisoryParser::fromReply($reply))->toBe([]);
});

it('strips markdown emphasis and a leading label', function () {
    $items = AdvisoryParser::fromReply("Caveats:\n- **Deadline:** The appeal period lapses fifteen days from receipt");

    expect($items[0]['title'])->toBe('The appeal period lapses fifteen days from receipt');
});

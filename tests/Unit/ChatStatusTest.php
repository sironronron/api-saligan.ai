<?php

use App\Support\ChatStatus;
use Tests\TestCase;

uses(TestCase::class);

it('labels gathering_facts with the document being drafted', function () {
    expect(ChatStatus::label('gathering_facts', 'Draft a demand letter for unpaid rent.'))
        ->toBe('Gathering the facts needed for your demand letter')
        ->and(ChatStatus::label('gathering_facts', "I need to write DAR requesting a certified copy of my late father's CLOA."))
        ->toBe('Gathering the facts needed for your government transaction letter');
});

it('labels drafting_document with the document being drafted', function () {
    expect(ChatStatus::label('drafting_document', 'Draft me a reklamo for illegal occupation.'))
        ->toBe('Drafting your complaint')
        ->and(ChatStatus::label('drafting_document', 'Draft an affidavit of loss.'))
        ->toBe('Drafting your affidavit')
        ->and(ChatStatus::label('drafting_document', 'Draft a deed of sale for my land.'))
        ->toBe('Drafting your deed of absolute sale');
});

it('falls back to a neutral drafting label when no document is named', function () {
    expect(ChatStatus::label('drafting_document', 'What is the prescriptive period for filing a case?'))
        ->toBe('Drafting your document…')
        ->and(ChatStatus::label('drafting_document', "[Intake Form Submission]\nsender_name: Juan Dela Cruz"))
        ->toBe('Drafting your document…')
        ->and(ChatStatus::label('preparing_next_steps', 'What is the prescriptive period for filing a case?'))
        ->toBe('Preparing your next-steps checklist…');
});

it('labels preparing_next_steps with the document being drafted', function () {
    expect(ChatStatus::label('preparing_next_steps', 'Draft a demand letter for unpaid rent.'))
        ->toBe('Preparing the next steps for your demand letter');
});

it('keeps personalizing the existing statuses', function () {
    expect(ChatStatus::label('checking_sources', 'Explain RA 6657, please.'))
        ->toBe('Checking legal sources about RA 6657')
        ->and(ChatStatus::label('composing', 'Explain RA 6657, please.'))
        ->toBe('Composing your answer about RA 6657');
});

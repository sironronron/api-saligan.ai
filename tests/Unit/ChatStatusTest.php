<?php

use App\Support\ChatStatus;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Step labels
|--------------------------------------------------------------------------
|
| Labels name only the action. The question's topic used to be appended to
| every one of them, which repeated the same phrase on the card heading and
| on each timeline row beneath it — and repeated any flaw in the extraction
| along with it. The topic now travels once, on its own field.
|
*/

it('names only the action, never the topic', function () {
    expect(ChatStatus::label('checking_sources', 'Explain RA 6657, please.'))
        ->toBe('Checking legal sources')
        ->and(ChatStatus::label('composing', 'Explain RA 6657, please.'))
        ->toBe('Writing your answer')
        ->and(ChatStatus::label('searching_web', 'Explain RA 6657, please.'))
        ->toBe('Searching the web');
});

it('still names the document on the drafting steps', function () {
    // A document name reads naturally in the step itself and is drawn from the
    // template library rather than guessed from the words, so it stays.
    expect(ChatStatus::label('drafting_document', 'Draft me a reklamo for illegal occupation.'))
        ->toBe('Drafting your complaint')
        ->and(ChatStatus::label('drafting_document', 'Draft an affidavit of loss.'))
        ->toBe('Drafting your affidavit')
        ->and(ChatStatus::label('gathering_facts', 'Draft a demand letter for unpaid rent.'))
        ->toBe('Gathering the facts for your demand letter');
});

it('falls back to a neutral label when no document is named', function () {
    expect(ChatStatus::label('drafting_document', 'What is the prescriptive period for filing a case?'))
        ->toBe('Drafting your document')
        ->and(ChatStatus::label('drafting_document', "[Intake Form Submission]\nsender_name: Juan Dela Cruz"))
        ->toBe('Drafting your document')
        ->and(ChatStatus::label('preparing_next_steps', 'What is the prescriptive period for filing a case?'))
        ->toBe('Preparing your next steps');
});

/*
|--------------------------------------------------------------------------
| Topic extraction
|--------------------------------------------------------------------------
|
| The reported bug: "What is the scope of the Comprehensive Agrarian Reform
| Program?" produced "Is the scope of the". The stem list was ordered
| shortest-first and only one stem was stripped, so "what" matched before
| "what is" ever could and the leftover "is the scope of the" became the
| topic.
|
*/

it('does not leave a question stem stranded at the front of the topic', function () {
    expect(ChatStatus::topic('What is the scope of the Comprehensive Agrarian Reform Program?'))
        ->toBe('Comprehensive Agrarian Reform Program');
});

it('prefers the named thing over the first few words', function () {
    expect(ChatStatus::topic('Tell me about the Department of Agrarian Reform'))
        ->toBe('Department of Agrarian Reform')
        ->and(ChatStatus::topic('How does the Comprehensive Agrarian Reform Program work?'))
        ->toBe('Comprehensive Agrarian Reform Program');
});

it('reads a legal citation straight out of the question', function () {
    expect(ChatStatus::topic('Explain RA 6657, please.'))->toBe('RA 6657')
        ->and(ChatStatus::topic('What does Presidential Decree No. 27 cover?'))->toBe('PD 27')
        ->and(ChatStatus::topic('Is this covered by the Family Code?'))->toBe('Family Code');
});

it('strips stems and pronouns without eating the question', function () {
    expect(ChatStatus::topic('How do I file a case for illegal dismissal?'))
        ->toBe('File a case for illegal dismissal')
        ->and(ChatStatus::topic('Can I terminate a lease early?'))
        ->toBe('Terminate a lease early');
});

it('never ends the topic on a dangling connector', function () {
    // "requirements for a deed of absolute" losing its "sale" was the symptom
    // of a word budget that cut mid-phrase.
    $topic = ChatStatus::topic('what are the requirements for a deed of absolute sale');

    expect($topic)->toBe('Requirements for a deed of absolute sale');

    foreach (['of', 'and', 'for', 'the', 'a', 'an', 'to', 'in', 'on'] as $connector) {
        expect(str_ends_with(mb_strtolower((string) $topic), ' '.$connector))->toBeFalse();
    }
});

it('has no topic for an intake submission or an empty message', function () {
    expect(ChatStatus::topic("[Intake Form Submission]\nsender_name: Juan"))->toBeNull()
        ->and(ChatStatus::topic('   '))->toBeNull()
        ->and(ChatStatus::topic('what'))->toBeNull();
});

it('keeps the topic short enough to sit on one line', function () {
    $topic = ChatStatus::topic(
        'What is the procedure for the judicial confirmation of an imperfect title over '
        .'agricultural land situated within a reclassified municipality?',
    );

    expect(mb_strlen((string) $topic))->toBeLessThanOrEqual(61);
});

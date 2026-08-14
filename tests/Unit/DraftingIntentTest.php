<?php

use App\Support\DraftingIntent;

/**
 * The model occasionally mangles the TODO block markers — wrapping them in
 * markdown bold ("**[TODO_START]**"), dropping to single brackets
 * ("[TODO_START]"), or prefixing them with a list dash ("-[TODO_END]"). The
 * checklist extraction must still find the block so fallback todos are
 * created even when the tool call was skipped.
 */
it('extracts steps between canonical double-bracket markers', function () {
    $draft = "[[TODO_START]]\n- Secure a certified copy.\n- Coordinate with your lawyer.\n[[TODO_END]]";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toBe(['Secure a certified copy.', 'Coordinate with your lawyer.']);
});

it('extracts steps when the markers are wrapped in bold', function () {
    $draft = "**[TODO_START]**\n- Secure a certified copy.\n- Coordinate with your lawyer.\n**[[TODO_END]]**";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toBe(['Secure a certified copy.', 'Coordinate with your lawyer.']);
});

it('extracts steps when the markers use single brackets', function () {
    $draft = "[TODO_START]\n- Secure a certified copy.\n- Coordinate with your lawyer.\n[TODO_END]";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toBe(['Secure a certified copy.', 'Coordinate with your lawyer.']);
});

it('extracts steps when the closing marker is prefixed with a dash', function () {
    $draft = "**[TODO_START]**\n- Secure a certified copy.\n- Coordinate with your lawyer.\n-[TODO_END]";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toBe(['Secure a certified copy.', 'Coordinate with your lawyer.']);
});

it('extracts steps from the reported malformed block', function () {
    $draft = <<<'DRAFT'
**[TODO_START]**

    Secure a certified copy of the Relocation Survey Report from Engr. Villafuerte.
    Coordinate with your lawyer to confirm the next hearing date for Case No. Q-24-08213.
    Prepare witnesses to testify regarding the history of the property boundaries.
    Organize documents proving actual and moral damages related to the 85 sqm loss.

-[TODO_END]
DRAFT;

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toContain('Secure a certified copy of the Relocation Survey Report from Engr. Villafuerte.')
        ->toContain('Coordinate with your lawyer to confirm the next hearing date for Case No. Q-24-08213.')
        ->toContain('Prepare witnesses to testify regarding the history of the property boundaries.')
        ->toContain('Organize documents proving actual and moral damages related to the 85 sqm loss.');
});

it('does not treat a plain sentence containing the words as a marker', function () {
    $draft = "The TODO_START of this matter is the hearing.\n- A real step.";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->not->toContain('The TODO_START of this matter is the hearing.')
        ->not->toContain('A real step.');
});

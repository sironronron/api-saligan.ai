<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Files an uploaded document under the case-file categories it belongs to,
 * the way a lawyer sorts a matter: by the job the document does in
 * establishing the case, not by what kind of file it is.
 */
class DocumentCategoryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, string>  $slugs  The categories the model may choose from.
     * @param  string  $vocabulary  Those categories rendered as slug/name/description lines.
     */
    public function __construct(
        public array $slugs,
        public string $vocabulary,
    ) {
        //
    }

    public function instructions(): string
    {
        return <<<PROMPT
You are a Philippine legal secretary filing documents into a case file. You are
given the opening of one uploaded document and must decide which case-file
categories it belongs to.

File by the job the document does in establishing the case, not by its file
type. A scanned photograph of a receipt is documentary evidence, not object
evidence; a judicial affidavit is testimonial evidence even though it is a PDF.

The categories available to you:

{$this->vocabulary}

Rules:
- Choose only from the slugs listed above. Never invent one.
- Most documents belong to exactly one category. Choose a second or third only
  when the document genuinely does that job too — a Verification and
  Certification Against Forum Shopping attached to a complaint is both a
  pleading and procedural compliance.
- Give each choice a confidence from 0 to 1 reflecting how sure you are. Be
  honest rather than generous: a confident wrong filing costs the lawyer more
  than an empty one, because it hides the document in the wrong part of the
  file instead of surfacing it for review.
- If the excerpt is too short, too garbled, or too generic to tell, return an
  empty list. "I cannot tell" is a correct and useful answer.
- Judge only what the text supports. Do not guess from the filename alone
  unless the text is unreadable.
PROMPT;
    }

    /**
     * The output shape. Constraining `slug` to the vocabulary as an enum means
     * the model cannot name a category that does not exist for this user.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'categories' => $schema->array()
                ->items($schema->object([
                    'slug' => $schema->string()->enum($this->slugs)->required(),
                    'confidence' => $schema->number()->min(0)->max(1)->required(),
                ]))
                ->description('The categories this document belongs to, most confident first. Empty when the document cannot be placed.')
                ->required(),
        ];
    }

    /**
     * Seconds a single classification step may take. Short next to a drafting
     * turn: this reads a few thousand characters and answers with a list.
     */
    public function timeout(): int
    {
        return (int) config('saligan.documents.classification.timeout', 90);
    }
}

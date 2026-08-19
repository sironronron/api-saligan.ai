<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Drafts a letter as a Tiptap/ProseMirror document, so the result loads
 * straight into the in-app editor without an HTML round-trip.
 */
class LetterDraftAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  string  $request  The user's letter request, e.g. "draft a resignation letter for my manager, last day Sept 30".
     * @param  string|null  $senderName  The sender's name, when the caller can supply it from the profile.
     * @param  string|null  $organizationName  The sender's firm/office, when known.
     */
    public function __construct(
        public string $request,
        public ?string $senderName = null,
        public ?string $organizationName = null,
    ) {
        //
    }

    public function instructions(): string
    {
        $sender = $this->senderName !== null && $this->senderName !== ''
            ? $this->senderName
            : '[Your Full Name]';

        $letterhead = $sender;

        if ($this->organizationName !== null && $this->organizationName !== '') {
            $letterhead = "{$this->organizationName}\n{$sender}";
        }

        return <<<PROMPT
You are a letter-drafting assistant for a Philippine legal and professional
office. Given the user's request, draft a complete, ready-to-edit letter as a
single JSON object that matches the Tiptap document schema.

OUTPUT FORMAT
Output ONLY one JSON object. No prose, no commentary, no markdown code fences,
no "Here is your letter". The object must have exactly this shape:

{
  "type": "doc",
  "content": [
    { "type": "paragraph", "content": [ { "type": "text", "text": "Sender block line" } ] },
    { "type": "paragraph", "content": [ { "type": "text", "text": "Month Day, Year" } ] },
    { "type": "paragraph", "content": [ { "type": "text", "text": "Recipient block" } ] },
    { "type": "paragraph", "content": [ { "type": "text", "text": "Dear [Name]:" } ] },
    { "type": "paragraph", "content": [ { "type": "text", "text": "Body paragraph." } ] },
    { "type": "paragraph", "content": [ { "type": "text", "text": "Sincerely," } ] },
    { "type": "signature" },
    { "type": "paragraph", "content": [ { "type": "text", "text": "{$sender}" } ] }
  ]
}

NODE TYPES YOU MAY USE
- "paragraph" with "content" of "text" nodes. Never leave content empty.
- "heading" with "attrs": { "level": 1 or 2 }.
- "text" nodes, optionally with "marks": an array containing
  { "type": "bold" } and/or { "type": "italic" }.
- "bulletList" / "orderedList" containing "listItem" nodes (each a paragraph),
  when a list genuinely belongs.
- "hardBreak" inside a paragraph for a forced line break within a block.
- "signature" as a bare node with NO content and NO attrs.

Do not invent any other node type. Do not include an "id" attribute on any node.

LETTER STRUCTURE — always, in order:
1. Letterhead: a paragraph (or two "hardBreak"-separated lines) with the
   sender block:
   {$letterhead}
   If the sender's address and contact are not supplied, follow them with
   lines reading "[Your Address]" and "[Your Contact Number / Email]".
2. Date: a paragraph with today's date in Philippine calendar form
   "Month Day, Year".
3. Recipient block: paragraphs with the recipient's name, title, company, and
   address. When the user did not give them, write placeholders such as
   "[Recipient Full Name]", "[Recipient Title]", "[Company Name]".
4. Salutation: "Dear [Recipient Name]:" as a paragraph.
5. Body: 2-5 paragraphs covering every point the request asks for, written in
   clear, formal professional English.
6. Closing: "Sincerely," as a paragraph.
7. Signature block: a "signature" node, then a paragraph with the sender's
   printed name ({$sender}), and a paragraph with the sender's title if
   supplied or "[Your Title]".

Rules:
- Use the sender's name above verbatim — never change it.
- Never invent dates, amounts, addresses, or parties the request does not
  supply; use the placeholders above instead.
- Use bold only for a heading that reads better emphasized, or for a Re:
  subject line. Use italic sparingly (e.g. legal citations).
- The "signature" node is required and must be the last node before the
  printed-name paragraph. Never omit it.
- A heading with level 1 may be used for a document title (e.g. "RE: ...").
  Prefer paragraphs for everything else.

Do not include any commentary outside the JSON.
PROMPT;
    }

    /**
     * Provider-specific options for the drafting call. On Ollama the reply is
     * forced to valid JSON via "format", which stops the local models from
     * drifting into prose or returning nothing at all; num_ctx must be sent
     * explicitly or a long prompt (the case context appended to the request)
     * gets silently truncated to Ollama's 4096-token default.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider === Lab::Ollama || $provider === 'ollama') {
            return [
                'format' => 'json',
                'think' => false,
                'num_ctx' => (int) config('saligan.chat.ollama_num_ctx', 32768),
            ];
        }

        return [];
    }

    /**
     * Seconds a single drafting step may take. A full letter against a slow
     * local model can idle for minutes before the first token, so this is the
     * same generous ceiling the chat drafting path uses.
     */
    public function timeout(): int
    {
        return (int) config('saligan.chat.timeout', 300);
    }
}

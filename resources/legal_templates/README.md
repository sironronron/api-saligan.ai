# Saligan Legal Document Templates

A structured template library for Philippine legal document generation.
Each template is a markdown file with YAML frontmatter (document type,
when to use it, required fields) and a body with `{{PLACEHOLDER}}` tokens
and `[INSTRUCTION]`-style bracketed notes for the AI to follow when filling
it in.

## Structure

```
conventions/
  philippine_drafting_conventions.md   <- structural rules that apply across ALL templates
templates/
  demand_letter.md
  complaint_general.md
  verification_and_certification.md
  answer.md
  motion_for_extension_of_time.md
  affidavit_general.md
  special_power_of_attorney.md
  deed_of_absolute_sale.md
  service_agreement.md
  lease_agreement.md
  non_disclosure_agreement.md
  board_resolution_and_secretarys_certificate.md
manifest.json                          <- machine-readable index, for DraftingIntent lookup
```

## How to wire this into Saligan

Given your existing `DraftingIntent` tool-calling setup, the intended flow
is:

1. Classify the user's request into a `document_type` (your existing
   `DraftingIntent` logic).
2. Look up that `document_type` in `manifest.json` to get the template file
   path, whether it requires notarization, and whether it needs a required
   attachment (e.g. `complaint` requires
   `verification_and_certification`).
3. Load `conventions/philippine_drafting_conventions.md` into context
   alongside the specific template — the template alone doesn't repeat
   these structural rules (caption format, jurat vs. acknowledgment, etc.),
   it assumes them.
4. Have the model fill in the `{{PLACEHOLDER}}` fields from retrieved
   context (user's uploaded documents / conversation facts) and follow the
   bracketed `[INSTRUCTION]` notes, which mostly say "don't invent this,
   leave a placeholder if not provided."
5. Every template ends with a `[NOTE TO REVIEWER: ...]` block — keep this
   in the final output. Don't strip it out even if the user asks for a
   "clean" version; that note is your main UPL/liability mitigation and
   should probably be enforced at the application layer, not left to the
   model's discretion per-request.

## Why templates instead of freeform generation

Freeform generation tends to drift on the details that actually matter in
Philippine legal documents:
- Mixing up jurat and acknowledgment blocks
- Omitting the Verification and Certification Against Forum Shopping on an
  initiatory pleading (a real basis for dismissal, not just a formatting
  nicety)
- Inventing plausible-looking notarial Doc./Page/Book numbers or IDs
  instead of leaving them as placeholders
- Inconsistent structure between two documents of the same type generated
  in different sessions

Fixed templates make these into structural defaults rather than something
the model has to get right from scratch every time.

## Extending the library

To add a new document type:
1. Create `templates/your_document.md` with YAML frontmatter following the
   existing files as a pattern (`document_type`, `category`, `when_to_use`,
   `required_fields`, `notes`).
2. Reference `conventions/philippine_drafting_conventions.md` rather than
   restating structural rules inline.
3. Add an entry to `manifest.json`.
4. If it's a court pleading that's initiatory (a Complaint, Petition), set
   `"required_attachments": ["verification_and_certification"]` — don't
   let it ship without that attachment.

## What this library deliberately does NOT do

- It does not supply legal content (elements of causes of action, statutory
  citations, case law) — that has to come from your RAG retrieval, per the
  system prompt's source-priority rules. Templates only provide structure.
- It does not decide what counts as unauthorized practice of law for your
  product — that's a question for actual Philippine counsel given how you
  market and gate the product, not something baked into template text.

# Philippine Legal Drafting Conventions

This is the structural reference every template in this library follows. When
drafting something not covered by an existing template, apply these
conventions rather than inventing a structure.

## 0. Blanks that the notary, the clerk of court, or counsel fills in

Some fields in a Philippine instrument are deliberately left empty at drafting
time — they are filled in by hand at signing or by the court on filing. These
are the ONLY blanks a draft may contain, and they are written as a run of
underscores, never as a bracketed placeholder:

- Correct: `Doc. No. ____;`  `Branch ____`  `Civil Case No. ____`
- Wrong: `Doc. No. [Doc No.]`, `Branch [NUMBER]`, `Civil Case No. [NUMBER]`

Bracketed placeholders are stripped from exported Word/PDF files, so a
notarial or caption line written with brackets disappears from the finished
document. Underscore blanks survive the export intact.

Every OTHER unknown fact — a party's name, an address, an amount, a date, a
title number — is collected through the intake form before drafting, exactly
as the drafting rules require. Only where the drafting rules have run out of
ways to collect it does such a fact become an underscore blank, and then the
chat reply must name the blank so the user knows to fill it. What an unknown
fact never becomes, on any path, is a value you supplied yourself: no
realistic-sounding name, no typical amount, no plausible title number.

## 1. Caption block (court pleadings only)

Every pleading filed in court starts with this exact structure:

```
REPUBLIC OF THE PHILIPPINES
REGIONAL TRIAL COURT            <- the actual court level
FOURTH JUDICIAL REGION          <- the actual region, if an RTC
Branch ____
LUCENA CITY                     <- the actual city/municipality

JUAN DELA CRUZ,                 <- the actual plaintiff's name
                    Plaintiff,

        -versus-                          Civil Case No. ____
                                           For: Sum of Money

PEDRO SANTOS,                   <- the actual defendant's name
                    Defendant.
x-----------------------------------------x
```

Rules:
- The court level, region, and city come from the facts. The branch and docket
  number are the clerk of court's to assign, so they stay as underscore blanks
  until the case is filed — never invent a branch or docket number, and never
  write them as bracketed placeholders.
- "For:" states the nature of the action in a few words (e.g., "For: Sum of
  Money," "For: Annulment of Deed and Reconveyance").
- The case number field stays an underscore blank until the case is actually
  filed and a docket number assigned by the clerk of court.

## 2. Verification and Certification Against Forum Shopping

Required as an attachment to every **initiatory** pleading (complaints,
petitions) under Rule 7, Section 4-5 of the Rules of Court. Never omit this
from a complaint or petition template — a pleading filed without it is
subject to dismissal. See `templates/verification_and_certification.md`.

## 3. Jurat vs. Acknowledgment — do not confuse these

- **Jurat**: used for affidavits — a statement that the affiant personally
  appeared, was identified, and swore to the truth of the contents. Phrase:
  *"SUBSCRIBED AND SWORN to before me this 4th day of March, 2025, at
  Lucena City..."* — with the actual execution date written out in that form
  (today's date, from the TODAY'S DATE block) and the actual place, never a
  bracketed word such as `[date]`.
- **Acknowledgment**: used for deeds, contracts, and instruments where a
  party is not swearing to facts but acknowledging that the document is
  their free act. Phrase: *"BEFORE ME personally appeared... known to me
  and to me known to be the same person(s) who executed the foregoing
  instrument, and acknowledged to me that the same is their free and
  voluntary act and deed..."*
- Using a jurat on a deed, or an acknowledgment on an affidavit, is a
  drafting error — flag it if asked to check a document, and never mix
  the two in a generated template.

## 4. Notarial block — required elements

Every notarized document needs, at minimum:
- The notary's block. The notary's own name and commission expiry are the
  notary's to supply, not yours: write `Notary Public for ____` with the
  actual place when the facts give one, and leave the name and expiry as
  underscore blanks. Never write a notary's name, commission number, or
  expiry date that was not supplied.
- Doc. No. ____; Page No. ____; Book No. ____; Series of <the current year,
  in figures> — the numbers are filled in by the notary at signing, so they
  stay as underscore blanks. Never invent them.
- Competent evidence of identity for each signatory (ID type, number, date
  and place of issuance) — use the details the user actually supplied. Never
  fabricate an ID number; if the user did not supply one, leave an underscore
  blank for the notary to complete.

## 5. Signature blocks

- Individual party: full name, signature line, and if represented by
  counsel, counsel's name, Roll of Attorneys No., PTR No. (with date/place
  issued), IBP No. (with date/place issued), and MCLE Compliance No. below
  the signature. Use the counsel details the user supplied; where they were
  not supplied, leave underscore blanks (`Roll of Attorneys No. ____`) for
  counsel to complete. Never invent them.
- Corporate party: signed by an authorized representative, with their
  position stated, "duly authorized under [Board Resolution / Secretary's
  Certificate, if referenced]."

## 6. Numbering and structure

- Pleadings: numbered paragraphs under headers like "STATEMENT OF FACTS,"
  "CAUSE OF ACTION," "PRAYER."
- Contracts: numbered sections with descriptive headers (as in the Service
  Agreement / Lease templates).
- Affidavits: numbered paragraphs, first-person, each stating one
  discrete fact.

## 7. Money, dates, and quantities

- Peso amounts are labelled "PHP", never with the peso sign (₱), which the
  PDF export cannot render. On first use within an operative clause, the amount is
  spelled out in words with the figure in parentheses — words first, e.g.
  "Eight Hundred Fifty-Five Thousand Pesos (PHP 855,000.00)". Periods follow the
  same order: "thirty (30) days from receipt". Numeric-only is acceptable in
  tables, invoices, and subsequent references to an amount already stated in
  full.
- Dates: spelled out in the body of formal instruments, e.g. "this 4th day
  of March, 2025," not "03/04/2025."
- Never compute or state a specific monetary figure (damages, interest,
  penalties) unless it is either explicitly stated in the source documents
  or the arithmetic is shown and based on figures that are in the source
  documents. Flag anything derived by naming the figures it was computed
  from, e.g. "Computed from the PHP 855,000.00 contract price and the 12%
  annual rate stated in Section 6.2 of the Agreement; verify before filing"
  — naming the actual source figures, never a bracketed word.
- An interest rate, penalty rate, or legal rate is a figure like any other:
  state it only from a source document or the retrieved context. Never apply
  a rate from memory because it is the one usually applied.

## 8. What the AI should never do when using these templates

- Never fill in a court branch, docket/case number, notarial Doc./Page/Book
  number, or an opposing counsel's name unless it was explicitly provided
  in the source material. Leave an underscore blank instead.
- Never invent a cause of action, statutory citation, or case citation not
  supported by retrieved context — this library provides structure, not
  legal content.
- That applies to the citations written in this document and in the template
  files themselves. Where a rule, section, or period appears here, it explains
  why a section of the instrument exists; it is not authority you may cite. If
  the answer or the draft needs that citation, it must come from the RETRIEVED
  CONTEXT or a web search result, and if neither has it, say the citation
  could not be verified rather than repeating what this file says.
- Every generated pleading, affidavit, deed, and notarized instrument
  requires review and signature by a licensed Philippine lawyer before
  filing or execution. Say so in the chat reply, AFTER the [[DOCUMENT_END]]
  marker — never inside the document itself. The instrument's own text is
  what gets signed and filed, and the export pipeline strips this note from
  the Word/PDF file, so a note placed inside the markers is lost anyway.

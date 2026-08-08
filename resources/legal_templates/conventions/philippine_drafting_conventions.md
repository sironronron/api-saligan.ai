# Philippine Legal Drafting Conventions

This is the structural reference every template in this library follows. If
Saligan is drafting something not covered by an existing template, it should
still apply these conventions rather than inventing its own structure.

## 1. Caption block (court pleadings only)

Every pleading filed in court starts with this exact structure:

```
REPUBLIC OF THE PHILIPPINES
[LEVEL OF COURT, e.g. REGIONAL TRIAL COURT]
[JUDICIAL REGION, if RTC, e.g. FOURTH JUDICIAL REGION]
Branch [NUMBER]
[CITY/MUNICIPALITY]

[PLAINTIFF/PETITIONER NAME],
                    Plaintiff,

        -versus-                          Civil Case No. [NUMBER]
                                           For: [NATURE OF ACTION]

[DEFENDANT/RESPONDENT NAME],
                    Defendant.
x-----------------------------------------x
```

Rules:
- Court level and branch are left blank with a clear placeholder if not yet
  known (case is pre-filing) — never invent a branch or docket number.
- "For:" states the nature of the action in a few words (e.g., "For: Sum of
  Money," "For: Annulment of Deed and Reconveyance").
- The case number field stays blank/placeholder until the case is actually
  filed and a docket number assigned by the clerk of court.

## 2. Verification and Certification Against Forum Shopping

Required as an attachment to every **initiatory** pleading (complaints,
petitions) under Rule 7, Section 4-5 of the Rules of Court. Never omit this
from a complaint or petition template — a pleading filed without it is
subject to dismissal. See `templates/verification_and_certification.md`.

## 3. Jurat vs. Acknowledgment — do not confuse these

- **Jurat**: used for affidavits — a statement that the affiant personally
  appeared, was identified, and swore to the truth of the contents. Phrase:
  *"SUBSCRIBED AND SWORN to before me this [date]..."*
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
- Notary's name, "Notary Public for [place], until [expiry]" or
  "Notary Public for [place]"
- Doc. No., Page No., Book No., Series of [year] (filled in by the notary
  at signing — leave as placeholders, never invent numbers)
- Competent evidence of identity for each signatory (ID type, number, date
  and place of issuance) — do not fabricate ID numbers; leave as a
  placeholder field to be filled from the actual document or left blank
  for the notary to complete.

## 5. Signature blocks

- Individual party: full name, signature line, and if represented by
  counsel, counsel's name, Roll of Attorneys No., PTR No. (with date/place
  issued), IBP No. (with date/place issued), and MCLE Compliance No. below
  the signature — these are placeholders unless actual counsel details were
  provided; never invent them.
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

- Peso amounts: numeric figure with the amount spelled out in words on
  first use within an operative clause, e.g. "₱855,000.00 (Eight Hundred
  Fifty-Five Thousand Pesos)." Numeric-only is acceptable in tables,
  invoices, or subsequent references.
- Dates: spelled out in the body of formal instruments, e.g. "this 4th day
  of March, 2025," not "03/04/2025."
- Never compute or state a specific monetary figure (damages, interest,
  penalties) unless it is either explicitly stated in the source documents
  or the arithmetic is shown and based on figures that are in the source
  documents. Flag anything derived as "Computed based on [X]; verify before
  filing."

## 8. What the AI should never do when using these templates

- Never fill in a court branch, docket/case number, notarial Doc./Page/Book
  number, or an opposing counsel's name unless it was explicitly provided
  in the source material. Leave a clearly marked placeholder instead.
- Never invent a cause of action, statutory citation, or case citation not
  supported by retrieved context — this library provides structure, not
  legal content.
- Always close generated pleadings, affidavits, deeds, and notarized
  instruments with a visible note that the document requires review and
  signature by a licensed Philippine lawyer before filing or execution.

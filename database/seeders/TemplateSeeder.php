<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Seed the system letter template library.
     */
    public function run(): void
    {
        $templates = $this->templates();

        foreach ($templates as $template) {
            Template::updateOrCreate(
                ['name' => $template['name'], 'category' => $template['category'], 'legal_subtype' => $template['legal_subtype']],
                array_merge($template, ['user_id' => null]),
            );
        }
    }

    /**
     * The system templates, including the Philippine legal-correspondence set.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function templates(): array
    {
        return [
            $this->formalTemplate(),
            $this->basicTemplate(),
            $this->demandLetter(),
            $this->noticeToExplain(),
            $this->noticeOfDecision(),
            $this->noticeOfTermination(),
            $this->barangayComplaint(),
            $this->replyToDemand(),
            $this->ceaseAndDesist(),
            $this->affidavit(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formalTemplate(): array
    {
        return [
            'name' => 'Formal Business Letter',
            'category' => 'formal',
            'jurisdiction' => 'PH',
            'legal_subtype' => null,
            'default_for_case_types' => ['legal', 'administrative', 'customer_support'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Salutation', 'Subject Line', 'Body', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'sender_name', 'label' => 'Sender name', 'required' => true],
                ['key' => 'sender_address', 'label' => 'Sender address', 'required' => true],
                ['key' => 'recipient_name', 'label' => 'Recipient name', 'required' => true],
                ['key' => 'recipient_address', 'label' => 'Recipient address', 'required' => true],
                ['key' => 'subject', 'label' => 'Subject / Re line', 'required' => false],
                ['key' => 'message', 'label' => 'Message body', 'required' => true],
            ],
            'content' => <<<'TEXT'
FORMAL LETTER CONVENTIONS (Philippines)
- Business-letter block format with the sender's letterhead area at the top.
- Date in `Month DD, YYYY` format (e.g. August 5, 2026).
- Recipient block: full name, title/position if applicable, then complete address on separate lines.
- Salutation: `Dear Sir/Madam`, or `Dear [Surname]` when the name is known.
- A `Re:` subject line summarizing the purpose.
- Closing: `Very truly yours,` followed by signatory name and position/company.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function basicTemplate(): array
    {
        return [
            'name' => 'Basic Correspondence',
            'category' => 'basic',
            'jurisdiction' => 'PH',
            'legal_subtype' => null,
            'default_for_case_types' => ['general', 'customer_support'],
            'structure' => ['Date', 'Recipient', 'Subject', 'Body', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'recipient_name', 'label' => 'Recipient name', 'required' => false],
                ['key' => 'date', 'label' => 'Date', 'required' => true],
                ['key' => 'subject', 'label' => 'Subject', 'required' => false],
                ['key' => 'message', 'label' => 'Message body', 'required' => true],
            ],
            'content' => <<<'TEXT'
BASIC CORRESPONDENCE CONVENTIONS
- Plain, minimal-structure correspondence.
- Date, recipient (if known), a short subject line, the message body in plain paragraphs, and a simple closing such as `Sincerely,` followed by the sender's name.
- Do not add a letterhead or legal formatting unless the sender's details were provided.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function demandLetter(): array
    {
        return [
            'name' => 'Demand Letter',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'demand_letter',
            'default_for_case_types' => ['legal', 'customer_support'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Salutation', 'Re: Demand for Payment', 'Statement of Obligation', 'Legal Basis', 'The Demand', 'Consequence of Non-Compliance', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'sender_name', 'label' => 'Sender / firm name', 'required' => true],
                ['key' => 'sender_address', 'label' => 'Sender address', 'required' => true],
                ['key' => 'recipient_name', 'label' => 'Recipient name', 'required' => true],
                ['key' => 'recipient_address', 'label' => 'Recipient address', 'required' => true],
                ['key' => 'amount_or_demand', 'label' => 'Exact amount or action demanded', 'required' => true],
                ['key' => 'compliance_days', 'label' => 'Number of days to comply', 'required' => true],
                ['key' => 'facts', 'label' => 'Statement of facts', 'required' => true],
                ['key' => 'legal_basis', 'label' => 'Legal basis (law / contract provision)', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE DEMAND LETTER CONVENTIONS
- Business-letter block format with sender's letterhead at top.
- Date format: `Month DD, YYYY`.
- Recipient block with full name, title if applicable, and complete address.
- Salutation: `Dear Sir/Madam` or `Dear [Surname]`.
- Subject/Re line citing the case reference and parties, e.g. `Re: Demand for Payment — [Case Reference]`.
- Body: (1) statement of the obligation or transaction; (2) legal basis (contract provision, RA/PD, or Civil Code article such as Art. 1169 on mora); (3) a clear demand with a specific compliance period stated in days (e.g. "within five (5) days from receipt"); (4) the consequence of non-compliance, e.g. filing a civil case for collection/sum of money, or referral to the barangay or Lupon under Katarungang Pambarangay for disputes falling within its jurisdiction.
- Closing: `Very truly yours,` followed by the signatory's name and position.
- Keep the tone firm but professional; avoid threats that are not legal consequences.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function noticeToExplain(): array
    {
        return [
            'name' => 'Notice to Explain (NTE)',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'notice_to_explain',
            'default_for_case_types' => ['hr'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Subject: Notice to Explain', 'Statement of Act/Omission', 'Company Policy / Labor Code Ground', 'Period to Respond', 'Consequence of Non-Response', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'company_name', 'label' => 'Company name', 'required' => true],
                ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                ['key' => 'employee_position', 'label' => 'Employee position', 'required' => false],
                ['key' => 'act_or_omission', 'label' => 'Specific act or omission charged', 'required' => true],
                ['key' => 'policy_or_ground', 'label' => 'Company policy / Labor Code provision', 'required' => true],
                ['key' => 'response_days', 'label' => 'Days to respond (min 5 per DOLE guidance)', 'required' => true],
                ['key' => 'response_medium', 'label' => 'How to submit the explanation', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE NOTICE TO EXPLAIN (NTE) CONVENTIONS
- Used for the first notice in the two-notice rule required for procedural due process in labor cases (DOLE guidance).
- Must identify the SPECIFIC act or omission the employee is charged with, with enough detail to allow a response.
- Must cite the applicable company policy or Labor Code provision (e.g. Art. 297 [formerly 282] on just causes).
- Must give a REASONABLE PERIOD to respond — commonly at least five (5) calendar days per DOLE guidance.
- Must state that the employee may submit a written explanation, attach supporting documents, and may be accompanied by counsel or a representative.
- Must state the possible consequence if the employee fails to respond (e.g. the company may consider the matter based on available records).
- Subject line: `Notice to Explain` referencing the incident date.
- Add a bilingual (English/Filipino) variant when the recipient is likely to respond in Filipino.
- Closing: `Very truly yours,` / `Respectfully yours,` with signatory name and position.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function noticeOfDecision(): array
    {
        return [
            'name' => 'Notice of Decision',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'notice_of_decision',
            'default_for_case_types' => ['hr'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Subject: Notice of Decision', 'Findings', 'Grounds', 'Decision', 'Effectivity', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'company_name', 'label' => 'Company name', 'required' => true],
                ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                ['key' => 'findings', 'label' => 'Findings after due process', 'required' => true],
                ['key' => 'grounds', 'label' => 'Grounds / Labor Code provision', 'required' => true],
                ['key' => 'decision', 'label' => 'The decision', 'required' => true],
                ['key' => 'effectivity_date', 'label' => 'Effectivity date', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE NOTICE OF DECISION CONVENTIONS
- Issued as the second notice after an NTE, following the two-notice rule.
- Must state the findings after evaluating the employee's explanation and the evidence.
- Must cite the ground for the decision under the Labor Code (e.g. Art. 297 on just causes or Art. 298 on authorized causes).
- Must state the decision clearly (e.g. disciplinary action, or termination effective on a stated date).
- Must state the effectivity date and any final pay / benefits that will be released.
- May state that the decision is based on the records in the absence of a response.
- Closing: `Very truly yours,` / `Respectfully yours,` with signatory name and position.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function noticeOfTermination(): array
    {
        return [
            'name' => 'Notice of Termination',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'notice_of_termination',
            'default_for_case_types' => ['hr'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Subject: Notice of Termination', 'Findings', 'Grounds', 'Termination Effective Date', 'Final Pay', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'company_name', 'label' => 'Company name', 'required' => true],
                ['key' => 'employee_name', 'label' => 'Employee name', 'required' => true],
                ['key' => 'findings', 'label' => 'Findings', 'required' => true],
                ['key' => 'grounds', 'label' => 'Grounds / Labor Code provision', 'required' => true],
                ['key' => 'termination_date', 'label' => 'Termination effective date', 'required' => true],
                ['key' => 'final_pay', 'label' => 'Final pay arrangement', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE NOTICE OF TERMINATION CONVENTIONS
- Second notice under the two-notice rule; follows a valid NTE and Notice of Decision.
- Must restate the findings and the ground under the Labor Code (Art. 297 just causes or Art. 298 authorized causes).
- Must state the date of termination and that the employee may claim their final pay and other benefits.
- Must be issued in writing and served on the employee, with proof of service where possible.
- Closing: `Very truly yours,` / `Respectfully yours,` with signatory name and position.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function barangayComplaint(): array
    {
        return [
            'name' => 'Barangay Complaint (Sumbong)',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'barangay_complaint',
            'default_for_case_types' => ['legal', 'general'],
            'structure' => ['Republic of the Philippines', 'Barangay Header', 'Case Details', 'Complainant', 'Respondent', 'Narration of Facts', 'Relief Sought', 'Verification', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'barangay_name', 'label' => 'Barangay / City / Municipality', 'required' => true],
                ['key' => 'complainant_name', 'label' => 'Complainant full name', 'required' => true],
                ['key' => 'complainant_address', 'label' => 'Complainant address', 'required' => true],
                ['key' => 'respondent_name', 'label' => 'Respondent full name', 'required' => true],
                ['key' => 'respondent_address', 'label' => 'Respondent address', 'required' => true],
                ['key' => 'facts', 'label' => 'Narration of facts', 'required' => true],
                ['key' => 'relief_sought', 'label' => 'Relief sought', 'required' => true],
            ],
            'content' => <<<'TEXT'
PHILIPPINE BARANGAY COMPLAINT / SUMBONG CONVENTIONS
- Addressed to the Punong Barangay / Lupon Tagapamayapa under the Local Government Code (RA 7160) and the Revised Katarungang Pambarangay Law (RA 11396).
- Header: Republic of the Philippines, Province, City/Municipality, Barangay, and the Lupon Tagapamayapa details.
- CAPTION: case number (if any) and the parties (Complainant vs. Respondent).
- Body: full names and addresses of the parties; a clear, chronological narration of facts; the specific relief sought (e.g. mediation/conciliation for an amicable settlement, payment, or restitution).
- Must note that disputes between parties residing in the same barangay generally require barangay conciliation before court filing (except those excluded by law).
- Verification: statement that the complaint is made under oath and that the facts are true to the affiant's knowledge.
- May use Filipino (sumbong) with English structure for clarity.
- Signatory: complainant's name over signature, with address.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function replyToDemand(): array
    {
        return [
            'name' => 'Reply to Demand Letter',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'reply_to_demand',
            'default_for_case_types' => ['legal', 'customer_support'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Re: Reply to Demand Letter', 'Acknowledgement', 'Response to Demand', 'Factual / Legal Rebuttal', 'Proposed Resolution', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'sender_name', 'label' => 'Sender / counsel name', 'required' => true],
                ['key' => 'recipient_name', 'label' => 'Demand letter sender / counsel', 'required' => true],
                ['key' => 'demand_letter_date', 'label' => 'Date of demand letter', 'required' => true],
                ['key' => 'response', 'label' => 'Response to the demand', 'required' => true],
                ['key' => 'rebuttal', 'label' => 'Factual / legal basis for the response', 'required' => true],
                ['key' => 'proposed_resolution', 'label' => 'Proposed resolution / offer', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE REPLY TO DEMAND LETTER CONVENTIONS
- Acknowledge receipt of the demand letter (state its date and subject).
- Respond point-by-point to the demand: admit, deny, or qualify each assertion with reasons.
- State the factual and legal basis for the response (e.g. Civil Code provisions on obligations, prescription, or the absence of the alleged obligation).
- Where appropriate, propose a resolution, counter-offer, or dispute-resolution path (e.g. barangay conciliation).
- Maintain a professional tone; do not make admissions of liability unless intended.
- Closing: `Very truly yours,` / `Respectfully yours,` with signatory name and position.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ceaseAndDesist(): array
    {
        return [
            'name' => 'Cease and Desist Letter',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'cease_and_desist',
            'default_for_case_types' => ['legal', 'customer_support'],
            'structure' => ['Letterhead', 'Date', 'Recipient', 'Re: Cease and Desist', 'Acts Complained Of', 'Legal Basis', 'Demand to Cease', 'Deadline', 'Consequence of Continued Acts', 'Closing', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'sender_name', 'label' => 'Sender / counsel name', 'required' => true],
                ['key' => 'recipient_name', 'label' => 'Recipient name', 'required' => true],
                ['key' => 'acts', 'label' => 'Acts to cease and desist from', 'required' => true],
                ['key' => 'legal_basis', 'label' => 'Legal basis (IP law, Civil Code, etc.)', 'required' => true],
                ['key' => 'deadline', 'label' => 'Deadline to comply (days)', 'required' => true],
                ['key' => 'consequence', 'label' => 'Consequence of continued acts', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE CEASE AND DESIST CONVENTIONS
- Identify the specific acts the recipient must stop (e.g. trademark infringement, defamatory posts, nuisance, continuing violation of an obligation).
- Cite the legal basis: RA 8293 (Intellectual Property Code), Civil Code provisions (e.g. Art. 26 on violations of privacy/dignity), or other applicable law.
- Demand that the acts cease immediately and within a stated period (e.g. "within three (3) days from receipt").
- State the consequence of continued non-compliance (e.g. filing an appropriate legal action, criminal complaint, or IPOPHL action) — only consequences that are real and lawful.
- Keep records: letterhead, date, and proof of service.
- Closing: `Very truly yours,` with signatory name and position.
TEXT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function affidavit(): array
    {
        return [
            'name' => 'Affidavit of Witness / Judicial Affidavit',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'affidavit',
            'default_for_case_types' => ['legal', 'general'],
            'structure' => ['Republic of the Philippines', 'Province/City', 'Affidavit Title', 'Statement of Facts', 'Verification / Jurat', 'Notarial Acknowledgment', 'Signature'],
            'placeholder_fields' => [
                ['key' => 'affiant_name', 'label' => 'Affiant full name', 'required' => true],
                ['key' => 'affiant_address', 'label' => 'Affiant address', 'required' => true],
                ['key' => 'affiant_occupation', 'label' => 'Affiant occupation', 'required' => false],
                ['key' => 'statement_facts', 'label' => 'Facts sworn to', 'required' => true],
                ['key' => 'place_of_execution', 'label' => 'Place of execution', 'required' => true],
                ['key' => 'purpose', 'label' => 'Purpose of the affidavit', 'required' => false],
            ],
            'content' => <<<'TEXT'
PHILIPPINE AFFIDAVIT CONVENTIONS
- Heading: REPUBLIC OF THE PHILIPPINES, Province/City, and the title of the document.
- Opening: the affiant states name, address, and (where relevant) occupation or civil status.
- Body: numbered, clear statements of the facts being sworn to, in the affiant's own knowledge; distinguish personal knowledge from information or belief.
- Jurat block: statement that the affiant "made oath" and signed the document, followed by the notarial acknowledgment.
- NOTARIAL ACKNOWLEDGMENT format (per 2004 Rules on Notarial Practice):
  "SUBSCRIBED AND SWORN to before me, this __ day of ______ 20__ at ______, Philippines. Affiant exhibited to me his/her valid government-issued identification (ID No. ______ issued on ______ at ______)."
  Followed by the notary public's signature, printed name, Roll of Attorneys No., IBP Lifetime/Current No., PTR No., and MCLE Compliance No., with the Notarial Commission details.
- Add the notarial details block: Doc. No. __; Page No. __; Book No. __; Series of __.
- Closing: the affiant's signature over printed name.
TEXT,
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class LegalDocumentSeeder extends Seeder
{
    /**
     * Version published by this seeder. Bump it (and add the new content below)
     * to force every user to accept the updated terms on their next page load.
     */
    private const VERSION = '1.1';

    /**
     * Effective date as stated in the document itself. Interpreted in Philippine
     * time and stored as UTC, so the document goes live when it says it does for
     * users rather than at UTC midnight.
     */
    private const EFFECTIVE_AT = '2026-08-12 00:00:00';

    private const EFFECTIVE_TIMEZONE = 'Asia/Manila';

    /**
     * Seed the Terms of Service and Privacy Policy.
     */
    public function run(): void
    {
        LegalDocument::updateOrCreate(
            [
                'type' => LegalDocument::TYPE_TERMS_PRIVACY,
                'version' => self::VERSION,
            ],
            [
                'title' => 'Terms of Service and Privacy Policy',
                'content' => $this->content(),
                'effective_at' => Carbon::parse(self::EFFECTIVE_AT, self::EFFECTIVE_TIMEZONE)->utc(),
            ],
        );
    }

    private function content(): string
    {
        return <<<'MARKDOWN'
# Terms of Service and Privacy Policy

**Version:** 1.1
**Effective Date:** 12 August 2026
**Last Updated:** 12 August 2026

---

## PLEASE READ THIS FIRST

**Batayan is an artificial intelligence tool. It is not a lawyer, and it makes mistakes.**

Before you accept these terms, you must understand the following:

1. **Batayan is not a licensed attorney, law firm, or legal professional**, and it is not a substitute for one. Nothing the Service produces is legal advice, and using the Service does not create a lawyer-client relationship between you and Saligan AI.
2. **The Service will sometimes be wrong.** AI systems generate text by prediction. They can and do produce statements that are inaccurate, incomplete, outdated, internally inconsistent, or entirely fabricated while appearing confident and well-reasoned.
3. **The Service can invent citations.** It may produce case names, G.R. numbers, docket numbers, statutory provisions, section numbers, dates, or quotations that do not exist, or that exist but do not say what the Service claims they say. **You must independently verify every authority against the primary source before relying on it or submitting it to any court, tribunal, agency, client, or counterparty.**
4. **The law changes and the Service may not reflect those changes.** Statutes are amended, rules are revised, and decisions are promulgated, reconsidered, or reversed. The Service may be unaware of recent developments.
5. **You remain fully responsible for your own work.** If you are a legal professional, your professional, ethical, and disciplinary obligations are unaffected by your use of the Service. Reviewing and verifying output is your responsibility, and it cannot be delegated to the Service.

If you are not willing to independently verify what the Service produces, do not use the Service.

---

## PART I: TERMS OF SERVICE

### 1. Acceptance of Terms

By accessing or using Batayan ("the Service"), you agree to be bound by these Terms of Service ("Terms"). If you do not agree to all of these Terms, you may not access or use the Service.

These Terms constitute a legally binding agreement between you ("User," "you," or "your") and Saligan AI ("Company," "we," "us," or "our").

### 2. Description of Service

Batayan is an AI-powered legal research assistant designed to support legal work in the Philippines. The Service includes:

- AI-assisted legal research and analysis
- Document generation and template management
- Case management and matter tracking
- Legal citation assistance
- Document review and analysis tools

The Service is a research and drafting aid. It is not a decision-maker, and it does not practise law.

### 3. Eligibility

The Service is intended for use by licensed legal professionals, law students, and authorized personnel in the Philippines. By using the Service, you represent and warrant that:

- You are at least 18 years of age
- You have the legal capacity to enter into binding agreements
- You are a licensed attorney, law student, or authorized legal professional in the Philippines
- All information you provide is accurate and current

If you are not a legal professional, you must not treat anything the Service produces as a substitute for consulting a qualified lawyer about your situation.

### 4. Account Registration and Security

You must register for an account to access the Service. You agree to:

- Provide accurate, current, and complete information during registration
- Maintain and update your information to keep it accurate and complete
- Maintain the security of your password and account
- Promptly notify us of any unauthorized use of your account
- Accept responsibility for all activities that occur under your account

### 5. Acceptable Use

You agree to use the Service only for lawful purposes and in accordance with these Terms. You agree not to:

- Use the Service for any illegal purpose or in violation of any law or regulation
- Present AI-generated content to any court, tribunal, agency, or client as verified work product without independently checking it
- Hold the Service out to any third party as the source of legal advice
- Attempt to gain unauthorized access to any portion of the Service or its related systems
- Interfere with or disrupt the Service or servers or networks connected to the Service
- Use automated means to access or use the Service without our express written permission
- Remove, alter, or obscure any proprietary notices or labels
- Use the Service to transmit any viruses, malware, or other harmful code
- Share your account credentials with any third party
- Use the Service to engage in any form of discrimination or harassment

### 6. Intellectual Property Rights

The Service and its original content, features, and functionality are owned by Saligan AI and are protected by international copyright, trademark, patent, trade secret, and other intellectual property or proprietary rights laws.

You retain ownership of the documents, files, and other materials you upload to the Service. You grant us a limited licence to process those materials solely to provide the Service to you.

### 7. AI-Generated Content

The Service uses artificial intelligence to generate legal research, analysis, and documents. You acknowledge and agree that:

- AI-generated content is provided for informational purposes only and is **not legal advice**
- AI-generated content **may be inaccurate, incomplete, outdated, or fabricated**, including citations, quotations, and procedural requirements
- You are solely responsible for reviewing, verifying, and approving any AI-generated content before relying on it, filing it, sending it, or acting on it
- We do not guarantee the accuracy, completeness, currency, or reliability of AI-generated content
- You must exercise your own professional judgment when using AI-generated content
- Identical or similar prompts may produce different results, and a result is not made correct by being repeated
- The Service does not verify its own output, and any confidence expressed in its wording is not evidence of accuracy

**Verification duty.** Every legal authority the Service cites must be checked against the primary source — the official text of the statute, rule, issuance, or decision — before you rely on it. Failure to do so may expose you to professional sanctions, adverse rulings, or liability, for which you are solely responsible.

### 8. Fees and Payment

The Service may require payment of fees. You agree to:

- Pay all fees associated with your use of the Service
- Provide accurate and complete payment information
- Authorize us to charge your designated payment method for all fees incurred
- Be responsible for any taxes associated with your use of the Service

### 9. Termination

We may terminate or suspend your access to the Service immediately, without prior notice or liability, for any reason, including without limitation if you breach these Terms.

Upon termination, your right to use the Service will cease immediately. You may terminate your account at any time by contacting us.

### 10. Limitation of Liability

To the maximum extent permitted by applicable law, in no event shall Saligan AI, its affiliates, agents, directors, employees, suppliers, or licensors be liable for any indirect, punitive, incidental, special, consequential, or exemplary damages, including without limitation damages for loss of profits, goodwill, use, data, or other intangible losses, arising out of or relating to the use of, or inability to use, this Service.

### 11. Disclaimer of Warranties

Your use of the Service is at your sole risk. The Service is provided on an "AS IS" and "AS AVAILABLE" basis, without warranties of any kind, whether express or implied, including but not limited to implied warranties of merchantability, fitness for a particular purpose, non-infringement, or course of performance.

**We expressly disclaim any warranty that the output of the Service is accurate, complete, current, or fit for any legal purpose.**

### 12. Governing Law

These Terms shall be governed by and construed in accordance with the laws of the Republic of the Philippines, without regard to its conflict of law principles.

### 13. Dispute Resolution

Any dispute arising out of or relating to these Terms or the Service shall be resolved through binding arbitration in accordance with the rules of the Philippine Dispute Resolution Center, Inc. (PDRCI).

### 14. Changes to Terms

We reserve the right to modify or replace these Terms at any time. If a revision is material, we will provide at least 30 days' notice prior to any new terms taking effect. What constitutes a material change will be determined at our sole discretion.

When we publish a new version, you will be asked to review and accept it before continuing to use the Service.

### 15. Severability

If any provision of these Terms is held to be unenforceable or invalid, such provision will be modified and interpreted to accomplish the objectives of such provision to the greatest extent possible under applicable law, and the remaining provisions will continue in full force and effect.

### 16. Entire Agreement

These Terms, together with the Privacy Policy, constitute the entire agreement between you and Saligan AI regarding the Service and supersede all prior and contemporaneous understandings, agreements, representations, and warranties, both written and oral.

### 17. Contact Information

If you have any questions about these Terms, please contact us at:

**Saligan AI**
Email: legal@saligan.ai
Website: https://saligan.ai

---

## PART II: PRIVACY POLICY

This Privacy Policy explains how we collect, use, and protect your personal information. We process personal data in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173), its Implementing Rules and Regulations, and the issuances of the National Privacy Commission ("NPC").

### 18. Information We Collect

**Information you provide:**

- Account information: name, email address, and password
- Professional information: role, area of practice, experience level, and intended use
- Organization information: organization name and your role within it
- Billing information: payment details processed by our payment provider
- Content: documents, files, case details, prompts, and messages you submit to the Service

**Information collected automatically:**

- Usage data: features accessed, queries made, and time spent in the Service
- Device and log data: IP address, browser type, operating system, and timestamps
- Cookies and similar technologies used to keep you signed in and to remember preferences

**Acceptance records:** when you accept these terms we record the date and time, the version and hash of the document you accepted, your IP address, and your browser user agent, as evidence of consent.

### 19. How We Use Your Information

We use your information to:

- Provide, operate, and maintain the Service
- Authenticate you and secure your account
- Process payments and manage subscriptions
- Generate AI responses to your queries and documents
- Improve the accuracy, safety, and reliability of the Service
- Communicate with you about the Service, including service and security notices
- Send marketing communications, but only where you have separately opted in
- Comply with legal obligations and enforce our Terms

### 20. How We Share Your Information

We do not sell your personal information. We share it only as follows:

- **Service providers:** hosting, storage, payment processing, email delivery, and analytics providers acting on our instructions under contract
- **AI processing providers:** third-party model providers that process your prompts and documents to generate responses, under agreements restricting their use of that content
- **Within your organization:** where you belong to an organization account, administrators of that organization may access matters and documents associated with it
- **Legal requirements:** where required by law, regulation, subpoena, court order, or lawful government request
- **Business transfers:** in connection with a merger, acquisition, or sale of assets, subject to this Policy

### 21. Data Security

We implement technical and organizational measures designed to protect your information, including encryption in transit, access controls, and audit logging.

No method of transmission or storage is completely secure. We cannot guarantee absolute security, and you should weigh this before submitting highly sensitive or privileged material to the Service.

We will notify you and the NPC of a personal data breach where required by the Data Privacy Act and NPC issuances.

### 22. Data Retention

We retain your information for as long as your account is active and for as long afterwards as is necessary to comply with legal obligations, resolve disputes, and enforce our agreements.

Acceptance records are retained for the life of the account and for such period thereafter as is necessary to evidence consent.

You may request deletion of your account and associated content as described below.

### 23. Your Rights

Under the Data Privacy Act of 2012, you have the right to:

- **Be informed** that your personal data is being processed
- **Access** the personal data we hold about you
- **Object** to processing, including processing for marketing
- **Rectify** inaccurate or incomplete personal data
- **Erasure or blocking** of personal data in the circumstances the law provides
- **Data portability**, where processing is by electronic means and the data is in a structured, commonly used format
- **Damages** for inaccurate, incomplete, outdated, false, unlawfully obtained, or unauthorized use of personal data
- **Lodge a complaint** with the National Privacy Commission

To exercise these rights, contact our Data Protection Officer using the details below.

### 24. International Data Transfers

Your information may be processed on servers located outside the Philippines, including by AI processing providers. Where personal data is transferred abroad, we take steps to ensure it remains protected to a standard consistent with the Data Privacy Act.

### 25. Children's Privacy

The Service is not directed to persons under 18 years of age, and we do not knowingly collect their personal information. If we learn that we have collected such information, we will delete it.

### 26. Cookies and Tracking Technologies

We use cookies and similar technologies to keep you signed in, remember your preferences, and understand how the Service is used. You may control cookies through your browser settings, but disabling them may prevent parts of the Service from working.

### 27. Third-Party Links

The Service may link to third-party websites, including sources of primary legal materials. We are not responsible for the content or privacy practices of those sites.

### 28. Changes to Privacy Policy

We may update this Privacy Policy from time to time. Material changes will be notified to you, and where required we will ask you to accept the updated document.

### 29. Data Protection Officer

Questions, requests, and complaints regarding personal data may be addressed to:

**Data Protection Officer, Saligan AI**
Email: dpo@saligan.ai

You may also lodge a complaint with the **National Privacy Commission**, 5th Floor, Delegation Building, PICC Complex, Pasay City, Metro Manila (privacy.gov.ph).

---

## PART III: AI LIMITATIONS AND ACKNOWLEDGMENTS

### 30. AI Technology Acknowledgment

You acknowledge and agree that:

**Not a lawyer.** Batayan is an artificial intelligence tool and is not a licensed attorney, law firm, or legal professional. The Service does not provide legal advice, does not create a lawyer-client relationship, and does not represent you in any matter.

**It makes mistakes.** The Service generates text by statistical prediction, not by legal reasoning or verification. It can produce output that is inaccurate, incomplete, outdated, internally inconsistent, or wholly fabricated, and it can do so in fluent, confident, and professional-sounding language. Fluency is not accuracy.

**Fabricated authorities.** The Service may cite cases, G.R. numbers, docket numbers, statutes, rules, issuances, sections, dates, or quotations that do not exist, or that exist but do not support the proposition stated. Every authority must be checked against the primary source before use.

**No professional judgment.** The Service cannot exercise the professional judgment, ethical assessment, client counselling, or strategic evaluation of a licensed attorney. It does not know the facts of your matter beyond what you provide, and it cannot tell when a question requires a lawyer.

**Not a substitute for counsel.** If you require legal advice, consult a lawyer licensed in the relevant jurisdiction. Do not rely on the Service to decide whether to act, file, settle, or refrain from acting, and do not use it to determine a deadline, a limitation period, or a procedural requirement without verification.

**Jurisdictional limitations.** The Service is designed for use in the Philippines. Laws and procedures vary by jurisdiction, and the Service may be unsuitable for use elsewhere.

**Currency of information.** The Service may not reflect recent amendments, new rules, or newly promulgated, reconsidered, or reversed decisions.

**Confidentiality.** While we implement security measures, you should consider your professional obligations of confidentiality and privilege before submitting sensitive client material to the Service.

**Professional responsibility.** If you are a legal professional, you retain full professional and ethical responsibility for your work product, including anything prepared with the assistance of the Service. Use of the Service is not a defence to any professional, ethical, or disciplinary obligation.

### 31. Limitation of Liability for AI-Generated Content

To the maximum extent permitted by applicable law, Saligan AI shall not be liable for any damages arising from your reliance on AI-generated content, including but not limited to:

- Legal judgments or decisions made based on AI-generated content
- Court filings, pleadings, or documents containing AI-generated content
- Citations to authorities that are inaccurate, superseded, or non-existent
- Missed deadlines or limitation periods
- Client advice or counsel based on AI-generated content
- Any professional, ethical, or disciplinary consequence arising from your use of the Service

---

## ACCEPTANCE

By clicking "I have read and agree to the Terms of Service and Privacy Policy," you acknowledge that you have read and understood this document in full, including the notice at the beginning and the AI Limitations and Acknowledgments in Part III, and you agree to be bound by it.

In particular, you acknowledge that **Batayan is not a lawyer, that its output can be wrong or fabricated, and that you are responsible for independently verifying everything you rely on.**
MARKDOWN;
    }
}

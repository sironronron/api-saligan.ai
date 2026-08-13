<?php

namespace Database\Seeders;

use App\Enums\LabelKind;
use App\Models\Label;
use Illuminate\Database\Seeder;

class LabelSeeder extends Seeder
{
    /**
     * The case-file categories a document may be filed under.
     *
     * The taxonomy follows how a lawyer actually establishes a case — cause of
     * action, then the elements, then the facts and the evidence proving each,
     * then the pleadings and the procedural compliance that carry them into
     * court — rather than following file types. Every template in the legal
     * template library maps onto exactly one of these.
     *
     * @var array<int, array{slug: string, name: string, group: string, description: string}>
     */
    protected const DOCUMENT_CATEGORIES = [
        [
            'slug' => 'research-memo',
            'name' => 'Research & Memoranda',
            'group' => 'Case theory',
            'description' => 'Legal research, memoranda of law, and jurisprudence supporting the theory of the case.',
        ],
        [
            'slug' => 'pleading',
            'name' => 'Pleadings',
            'group' => 'Pleadings & submissions',
            'description' => 'Complaint, Petition, Answer, Reply, Position Paper, Pre-Trial Brief.',
        ],
        [
            'slug' => 'motion',
            'name' => 'Motions',
            'group' => 'Pleadings & submissions',
            'description' => 'Motions, oppositions, and manifestations filed in the course of the case.',
        ],
        [
            'slug' => 'court-issuance',
            'name' => 'Court Issuances',
            'group' => 'Pleadings & submissions',
            'description' => 'Orders, resolutions, decisions, writs, subpoenas, and notices of hearing.',
        ],
        [
            'slug' => 'evidence-documentary',
            'name' => 'Documentary Evidence',
            'group' => 'Evidence',
            'description' => 'Contracts, deeds, titles, receipts, invoices, statements of account, payslips.',
        ],
        [
            'slug' => 'evidence-testimonial',
            'name' => 'Testimonial Evidence',
            'group' => 'Evidence',
            'description' => 'Judicial affidavits, sworn and counter-affidavits, and transcripts of testimony.',
        ],
        [
            'slug' => 'evidence-object',
            'name' => 'Object & Media Evidence',
            'group' => 'Evidence',
            'description' => 'Photographs, screenshots, audio or video recordings, and chat exports.',
        ],
        [
            'slug' => 'correspondence',
            'name' => 'Correspondence',
            'group' => 'Facts & communications',
            'description' => 'Demand letters, notices, and letters or emails exchanged between the parties.',
        ],
        [
            'slug' => 'client-intake',
            'name' => 'Client Intake',
            'group' => 'Facts & communications',
            'description' => "Intake sheets, the client's narrative, engagement letters, and meeting notes.",
        ],
        [
            'slug' => 'authority-id',
            'name' => 'Authority & Identity',
            'group' => 'Authority',
            'description' => 'Government IDs, Special Power of Attorney, board resolutions, secretary\'s certificates, SEC or DTI registration.',
        ],
        [
            'slug' => 'government-record',
            'name' => 'Government Records',
            'group' => 'Official records',
            'description' => 'PSA certificates, barangay certifications, police blotters, NBI clearances, and agency records.',
        ],
        [
            'slug' => 'financial-record',
            'name' => 'Financial Records',
            'group' => 'Official records',
            'description' => 'Bank statements, ledgers, payroll records, and BIR filings.',
        ],
        [
            'slug' => 'procedural-compliance',
            'name' => 'Procedural Compliance',
            'group' => 'Procedure',
            'description' => 'Verification and certification against forum shopping, proof of service, filing receipts, notarization records.',
        ],
        [
            'slug' => 'other',
            'name' => 'Other',
            'group' => 'Procedure',
            'description' => 'Anything that does not belong in the categories above.',
        ],
    ];

    /**
     * The tags a conversation thread may carry, describing the work happening
     * in the thread rather than the material it discusses.
     *
     * @var array<int, array{slug: string, name: string, description: string}>
     */
    protected const THREAD_TAGS = [
        ['slug' => 'drafting', 'name' => 'Drafting', 'description' => 'Producing a document.'],
        ['slug' => 'research', 'name' => 'Research', 'description' => 'Looking up law or jurisprudence.'],
        ['slug' => 'case-strategy', 'name' => 'Case Strategy', 'description' => 'Working out the theory and approach of the case.'],
        ['slug' => 'client-intake', 'name' => 'Client Intake', 'description' => 'Gathering the facts from the client.'],
        ['slug' => 'evidence-review', 'name' => 'Evidence Review', 'description' => 'Going through the evidence on hand.'],
        ['slug' => 'court-filing', 'name' => 'Court Filing', 'description' => 'Preparing or tracking a filing.'],
        ['slug' => 'deadlines', 'name' => 'Deadlines', 'description' => 'Periods, due dates, and reglementary deadlines.'],
        ['slug' => 'settlement', 'name' => 'Settlement', 'description' => 'Negotiation, mediation, or compromise.'],
        ['slug' => 'opposing-counsel', 'name' => 'Opposing Counsel', 'description' => 'Dealings with the other side.'],
        ['slug' => 'needs-review', 'name' => 'Needs Review', 'description' => 'Waiting on a second look before it is relied on.'],
        ['slug' => 'urgent', 'name' => 'Urgent', 'description' => 'Needs attention ahead of other work.'],
        ['slug' => 'privileged', 'name' => 'Privileged', 'description' => 'Attorney-client privileged or otherwise confidential.'],
    ];

    /**
     * Seed the system label vocabulary. Re-runnable: terms are matched on
     * their kind and slug, and system terms that have left the list are
     * removed while every custom term is left untouched.
     */
    public function run(): void
    {
        $position = 0;

        foreach (self::DOCUMENT_CATEGORIES as $category) {
            $this->upsertSystemLabel(LabelKind::DocumentCategory, $category, $position++);
        }

        $position = 0;

        foreach (self::THREAD_TAGS as $tag) {
            $this->upsertSystemLabel(LabelKind::ThreadTag, $tag, $position++);
        }

        $this->pruneRemovedSystemLabels();
    }

    /**
     * @param  array{slug: string, name: string, group?: string, description: string}  $attributes
     */
    protected function upsertSystemLabel(LabelKind $kind, array $attributes, int $position): void
    {
        Label::updateOrCreate(
            [
                'kind' => $kind,
                'slug' => $attributes['slug'],
                'organization_id' => null,
                'user_id' => null,
            ],
            [
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'group' => $attributes['group'] ?? null,
                'position' => $position,
            ],
        );
    }

    /**
     * Drop system terms that are no longer part of the vocabulary. Assignments
     * to them cascade away with the row; custom terms are never touched.
     */
    protected function pruneRemovedSystemLabels(): void
    {
        Label::query()
            ->whereNull('organization_id')
            ->whereNull('user_id')
            ->where('kind', LabelKind::DocumentCategory)
            ->whereNotIn('slug', array_column(self::DOCUMENT_CATEGORIES, 'slug'))
            ->delete();

        Label::query()
            ->whereNull('organization_id')
            ->whereNull('user_id')
            ->where('kind', LabelKind::ThreadTag)
            ->whereNotIn('slug', array_column(self::THREAD_TAGS, 'slug'))
            ->delete();
    }
}

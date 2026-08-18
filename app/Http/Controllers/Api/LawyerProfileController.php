<?php

namespace App\Http\Controllers\Api;

use App\Enums\LawyerVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LawyerProfileResource;
use App\Jobs\MatchWaitingRequests;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File as FileRule;

class LawyerProfileController extends Controller
{
    public function __construct(private readonly DocumentEncryptor $encryptor)
    {
        //
    }

    /**
     * The current user's lawyer profile (if any) plus the selectable options.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->lawyerProfile;

        $documentTypes = config('vetting.practice_area_document_types', []);

        $practiceAreaOptions = collect(config('vetting.practice_areas'))
            ->map(fn (array $area): array => [
                'value' => $area['value'],
                'label' => $area['label'],
                'documents' => $documentTypes[$area['value']] ?? [],
            ])
            ->all();

        return response()->json([
            'data' => $profile ? (new LawyerProfileResource($profile))->resolve() : null,
            'meta' => [
                'practice_area_options' => $practiceAreaOptions,
                'region_options' => config('vetting.regions'),
                'commission_required' => true,
            ],
        ]);
    }

    /**
     * Submit or re-submit a lawyer registration. Uploads the credential
     * documents (encrypted at rest), resets verification to pending, and leaves
     * the profile for the admin queue.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'bar_number' => ['required', 'string', 'max:100'],
            'bar_jurisdiction' => ['required', 'string', 'max:100'],
            'ptr_number' => ['nullable', 'string', 'max:100'],
            'practice_areas' => ['required', 'array', 'min:1', 'max:12'],
            'practice_areas.*' => ['string', Rule::in($this->practiceAreaValues())],
            'region' => ['required', 'string', Rule::in($this->regionValues())],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_notary' => ['sometimes', 'boolean'],
            'notarial_commission_number' => ['nullable', 'required_if:is_notary,true', 'string', 'max:100'],
            'notarial_commission_issuer' => ['nullable', 'required_if:is_notary,true', 'string', 'max:150'],
            'notarial_commission_expires_at' => ['nullable', 'required_if:is_notary,true', 'date', 'after:today'],
            'max_concurrent_assignments' => ['sometimes', 'integer', 'min:1', 'max:'.config('vetting.max_concurrent_assignments')],
            'id_document' => ['required_without:id_document_path', 'file', 'max:10240', $this->documentRule()],
            'bar_membership_document' => ['required_without:bar_membership_document_path', 'file', 'max:10240', $this->documentRule()],
        ]);

        $user = $request->user();
        $profile = $user->lawyerProfile;

        $data = [
            'full_name' => $validated['full_name'],
            'bar_number' => $validated['bar_number'],
            'bar_jurisdiction' => $validated['bar_jurisdiction'],
            'ptr_number' => $validated['ptr_number'] ?? null,
            'practice_areas' => $validated['practice_areas'],
            'region' => $validated['region'],
            'city' => $validated['city'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'is_notary' => $request->boolean('is_notary'),
            'notarial_commission_number' => $request->boolean('is_notary') ? ($validated['notarial_commission_number'] ?? null) : null,
            'notarial_commission_issuer' => $request->boolean('is_notary') ? ($validated['notarial_commission_issuer'] ?? null) : null,
            'notarial_commission_expires_at' => $request->boolean('is_notary') ? ($validated['notarial_commission_expires_at'] ?? null) : null,
            'max_concurrent_assignments' => $validated['max_concurrent_assignments']
                ?? $profile?->max_concurrent_assignments
                ?? config('vetting.max_concurrent_assignments'),
        ];

        if ($request->hasFile('id_document')) {
            $data['id_document_path'] = $this->storeCredential($request->file('id_document'));
        }

        if ($request->hasFile('bar_membership_document')) {
            $data['bar_membership_document_path'] = $this->storeCredential($request->file('bar_membership_document'));
        }

        $status = LawyerVerificationStatus::Pending;
        $data['verification_status'] = $status;
        $data['verification_reason'] = null;
        $data['verification_reviewed_at'] = null;

        if ($profile === null) {
            $profile = $user->lawyerProfile()->create($data);
        } else {
            $profile->update($data);
        }

        return (new LawyerProfileResource($profile->fresh()))->response()->setStatusCode(201);
    }

    /**
     * Update the parts of a lawyer's profile that do not require fresh
     * credential uploads: notification preferences and the availability toggle.
     * Changing practice areas or region re-triggers a light verification pass.
     */
    public function update(Request $request): LawyerProfileResource
    {
        $profile = $request->user()->lawyerProfile;

        abort_if($profile === null, 404, 'No lawyer profile found. Register first.');

        $validated = $request->validate([
            'practice_areas' => ['sometimes', 'array', 'min:1', 'max:12'],
            'practice_areas.*' => ['string', Rule::in($this->practiceAreaValues())],
            'region' => ['sometimes', 'string', Rule::in($this->regionValues())],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'max_concurrent_assignments' => ['sometimes', 'integer', 'min:1', 'max:'.config('vetting.max_concurrent_assignments')],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_sms' => ['sometimes', 'boolean'],
            'notify_push' => ['sometimes', 'boolean'],
            'notify_in_app' => ['sometimes', 'boolean'],
        ]);

        $changes = [];

        foreach (['practice_areas', 'region', 'city', 'phone', 'max_concurrent_assignments'] as $key) {
            if (array_key_exists($key, $validated)) {
                $changes[$key] = $validated[$key];
            }
        }

        foreach (['notify_email', 'notify_sms', 'notify_push', 'notify_in_app'] as $key) {
            if (array_key_exists($key, $validated)) {
                $changes[$key] = $request->boolean($key);
            }
        }

        // Practice area / region changes alter who the lawyer matches for, so
        // they send the profile back through a light review pass.
        $profileRelevant = array_intersect(
            array_keys($changes),
            ['practice_areas', 'region'],
        );

        if ($profileRelevant !== []) {
            $changes['profile_changed_at'] = now();
        }

        $profile->update($changes);

        return new LawyerProfileResource($profile->fresh());
    }

    /**
     * Toggle whether the lawyer can receive new document requests.
     */
    public function availability(Request $request): LawyerProfileResource
    {
        $profile = $request->user()->lawyerProfile;

        abort_if($profile === null, 404, 'No lawyer profile found. Register first.');

        $validated = $request->validate([
            'available' => ['required', 'boolean'],
        ]);

        abort_unless(
            $profile->verification_status === LawyerVerificationStatus::Verified,
            422,
            'Your profile must be verified before you can receive requests.',
        );

        $profile->update(['available' => $request->boolean('available')]);

        // A lawyer coming online may be the match a waiting request was holding
        // out for, so re-run matching for the requests they could take.
        if ($profile->fresh()->available) {
            MatchWaitingRequests::dispatch($profile->fresh());
        }

        return new LawyerProfileResource($profile->fresh());
    }

    /**
     * Store a credential document, encrypted at rest like all platform files.
     */
    protected function storeCredential($file): string
    {
        $storagePath = 'lawyer-documents/'.Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');

        if (config('saligan.documents.encrypt_at_rest', true)) {
            $this->encryptor->encrypt((string) ($file->getRealPath() ?: $file->getPathname()), $storagePath);
        } else {
            $file->storeAs('lawyer-documents', basename($storagePath));
        }

        return $storagePath;
    }

    /**
     * The file rule for credential uploads: a small, image/PDF-only document.
     */
    protected function documentRule(): FileRule
    {
        return Rule::file()->extensions(['pdf', 'jpg', 'jpeg', 'png', 'webp']);
    }

    /**
     * @return array<int, string>
     */
    protected function practiceAreaValues(): array
    {
        return array_column(config('vetting.practice_areas'), 'value');
    }

    /**
     * @return array<int, string>
     */
    protected function regionValues(): array
    {
        return array_column(config('vetting.regions'), 'value');
    }
}

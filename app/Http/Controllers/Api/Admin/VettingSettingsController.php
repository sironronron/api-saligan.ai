<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Vetting\VettingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin configuration for vetting fees and the platform's operational rules.
 */
class VettingSettingsController extends Controller
{
    public function __construct(private readonly VettingSettings $settings)
    {
        //
    }

    /**
     * The current fee and rule configuration.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'fees' => $this->settings->fees(),
                'rules' => $this->settings->rules(),
            ],
            'defaults' => [
                'notarization_fee' => config('vetting.default_notarization_fee'),
                'vetting_fee' => config('vetting.default_vetting_fee'),
                'commission_percent' => config('vetting.platform_commission_percent'),
                'escalation_hours' => config('vetting.escalation_hours'),
                'max_concurrent_assignments' => config('vetting.max_concurrent_assignments'),
            ],
        ]);
    }

    /**
     * Persist the fee and rule configuration.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fees.notarization_fee' => ['required', 'integer', 'min:0'],
            'fees.vetting_fee' => ['sometimes', 'integer', 'min:0'],
            'fees.overrides' => ['sometimes', 'array'],
            'rules.commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'rules.escalation_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'rules.max_concurrent_assignments' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'rules.match_pool_size' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $this->settings->saveFees($validated['fees']);
        $this->settings->saveRules($validated['rules'] ?? $this->settings->rules());

        return response()->json([
            'data' => [
                'fees' => $this->settings->fees(),
                'rules' => $this->settings->rules(),
            ],
        ]);
    }
}

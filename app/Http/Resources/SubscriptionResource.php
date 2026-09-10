<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\Billing\AiBudget;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /**
     * Transform the subscription into an array, including current usage.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Usage is per-seat on paid plans, so show the requesting member's own
        // counters — not the purchaser's — while the limit comes from the
        // shared plan they both sit on. Trials pool across the org.
        $displayUser = $request->user() ?? $this->user;
        $onTrial = $this->onTrial();
        $requester = $request->user();
        $canManageBilling = $requester !== null && (
            ($this->organization_id === null && $this->user_id === $requester->id)
            || ($this->organization_id !== null
                && $requester->organization_id === $this->organization_id
                && $requester->hasActiveMembership()
                && $requester->canManageOrganization())
        );

        $usage = function (string $key) use ($onTrial, $displayUser): array {
            return [
                'used' => $onTrial
                    ? PlanLimits::organizationUsed($displayUser, $key)
                    : PlanLimits::used($displayUser, $key),
                'limit' => PlanLimits::limitFor($displayUser, $key),
            ];
        };

        $plan = $this->whenLoaded('plan');

        $messages = $usage('messages_used');
        $overage = $displayUser ? PlanLimits::used($displayUser, 'messages_overage') : 0;
        $overageRate = $plan instanceof Plan ? $plan->overage_price : null;

        // The customer-facing meter: one percent of the anniversary window's
        // spend allowance, pooled across the subscription. Legacy count keys
        // stay below so older surfaces keep rendering while they migrate.
        $aiUsage = $displayUser
            ? AiBudget::customerSnapshot($displayUser)
            : AiBudget::emptyCustomerSnapshot();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'interval' => $this->interval,
            'plan' => new PlanResource($plan),
            'pending_plan_id' => $this->pending_plan_id,
            // PayPal approval URLs are continuation credentials. Do not hand
            // one for shared billing to a member who cannot manage the plan.
            'pending_plan_checkout_url' => $canManageBilling ? $this->pending_plan_checkout_url : null,
            'current_period_start' => $this->current_period_start?->toDateString(),
            'current_period_end' => $this->current_period_end?->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            // `on_trial` is the flag the UI should gate on, not the status
            // string: a lapsed trial keeps `status = trialing` but no longer
            // grants access, and conflating the two would show a countdown to
            // someone who has already lost it.
            'trial' => [
                'on_trial' => $this->onTrial(),
                'ends_at' => $this->trial_ends_at?->toIso8601String(),
                'days_remaining' => $this->trialDaysRemaining(),
            ],
            'seats' => [
                'purchased' => $this->seats_purchased,
                'price_per_seat' => $this->price_per_seat,
                'next_invoice_amount' => $this->nextInvoiceAmount(),
                'next_invoice_pesos' => round($this->nextInvoiceAmount() / 100, 2),
            ],
            'usage' => [
                'ai_usage' => $aiUsage,
                'messages' => $messages + [
                    'overage' => $overage,
                    'overage_rate' => $overageRate,
                    'overage_due_cents' => $overageRate !== null ? $overage * $overageRate : 0,
                    'overage_due_pesos' => $overageRate !== null ? round($overage * $overageRate / 100, 2) : 0,
                ],
                'documents' => $usage('documents_uploaded'),
                'active_cases' => [
                    'used' => $displayUser ? $displayUser->cases()
                        ->where('status', '!=', 'closed')
                        ->whereNull('archived_at')
                        ->count() : 0,
                    'limit' => $displayUser ? PlanLimits::limitFor($displayUser, 'active_cases') : null,
                ],
            ],
        ];
    }
}

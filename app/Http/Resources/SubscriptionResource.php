<?php

namespace App\Http\Resources;

use App\Models\Plan;
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
        $usage = function (string $key): array {
            return [
                'used' => PlanLimits::used($this->user, $key),
                'limit' => PlanLimits::limitFor($this->user, $key),
            ];
        };

        $plan = $this->whenLoaded('plan');

        $messages = $usage('messages_used');
        $overage = PlanLimits::used($this->user, 'messages_overage');
        $overageRate = $plan instanceof Plan ? $plan->overage_price : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'interval' => $this->interval,
            'plan' => new PlanResource($plan),
            'current_period_start' => $this->current_period_start?->toDateString(),
            'current_period_end' => $this->current_period_end?->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'seats' => [
                'purchased' => $this->seats_purchased,
                'price_per_seat' => $this->price_per_seat,
                'next_invoice_amount' => $this->nextInvoiceAmount(),
                'next_invoice_pesos' => round($this->nextInvoiceAmount() / 100, 2),
            ],
            'usage' => [
                'messages' => $messages + [
                    'overage' => $overage,
                    'overage_rate' => $overageRate,
                    'overage_due_cents' => $overageRate !== null ? $overage * $overageRate : 0,
                    'overage_due_pesos' => $overageRate !== null ? round($overage * $overageRate / 100, 2) : 0,
                ],
                'documents' => $usage('documents_uploaded'),
                'active_cases' => [
                    'used' => $this->user->cases()
                        ->where('status', '!=', 'closed')
                        ->whereNull('archived_at')
                        ->count(),
                    'limit' => PlanLimits::limitFor($this->user, 'active_cases'),
                ],
            ],
        ];
    }
}

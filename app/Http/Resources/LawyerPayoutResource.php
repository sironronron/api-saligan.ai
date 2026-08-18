<?php

namespace App\Http\Resources;

use App\Models\LawyerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LawyerPayout
 */
class LawyerPayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lawyer_id' => $this->lawyer_id,
            'lawyer_name' => $this->whenLoaded('lawyer', fn () => $this->lawyer?->name),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'gross_amount' => $this->gross_amount,
            'platform_fee' => $this->platform_fee,
            'lawyer_share' => $this->lawyer_share,
            'notarization_count' => $this->notarization_count,
            'status' => $this->status,
            'payout_ref' => $this->payout_ref,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    /**
     * Transform the plan into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'price' => $this->price,
            'price_label' => $this->priceLabel(),
            'price_annual' => $this->price_annual,
            'price_annual_label' => $this->priceAnnualLabel(),
            'overage_price' => $this->overage_price,
            'overage_label' => $this->overageLabel(),
            'currency' => $this->currency,
            'interval' => $this->interval,
            'limits' => $this->limits,
            'features' => $this->features,
            'sort_order' => $this->sort_order,
        ];
    }
}

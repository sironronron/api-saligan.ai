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
            // How many people the list price covers, and what one more costs.
            // `seat_price` of null means the plan does not sell seats at all,
            // which is a different statement from selling them for nothing.
            'included_seats' => $this->included_seats,
            'seat_price' => $this->seat_price,
            'seat_price_label' => $this->seatPriceLabel(),
            'limits' => $this->limits,
            'features' => $this->features,
            // The client shows a "talk to us" card instead of a price and a
            // buy button when this is set; checkout refuses the plan either way.
            'contact_sales' => (bool) $this->contact_sales,
            'sort_order' => $this->sort_order,
        ];
    }
}

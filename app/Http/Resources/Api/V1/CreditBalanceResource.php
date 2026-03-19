<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditBalanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->whenLoaded('subscriptions', fn () => $this->subscriptions->first());
        $period = $this->whenLoaded('usagePeriods', fn () => $this->usagePeriods->first());

        return [
            'workspace_id' => $this->id,
            'plan' => [
                'name' => $subscription?->plan?->name,
                'slug' => $subscription?->plan?->slug,
            ],
            'billing_interval' => $subscription?->billing_interval?->value,
            'credits' => [
                'limit' => $period?->credits_limit,
                'used' => $period?->credits_used,
                'remaining' => $period?->creditsRemaining(),
                'from_packs' => $period?->credits_from_packs,
                'rolled_over' => $period?->credits_rolled_over,
            ],
            'period' => [
                'start' => $period?->period_start?->toDateString(),
                'end' => $period?->period_end?->toDateString(),
            ],
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class WorkspaceService
{
    /**
     * Create a new workspace for the given user.
     *
     * @param  array{name: string}  $data
     */
    public function create(User $owner, array $data): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $data['name'],
            'slug' => $this->generateUniqueSlug($data['name']),
            'owner_id' => $owner->id,
        ]);

        // Assign owner to workspace_members
        $workspace->members()->attach($owner->id, [
            'role' => Role::Owner->value,
            'joined_at' => now(),
        ]);

        // Bootstrap billing state
        $this->bootstrapBilling($workspace);

        return $workspace;
    }

    /**
     * Bootstrap billing state for a new workspace.
     */
    private function bootstrapBilling(Workspace $workspace): void
    {
        // Look up the 'free' plan
        $freePlan = Plan::query()->where('slug', 'free')->firstOrFail();
        $monthlyCredits = $freePlan->getLimit('credits_monthly');

        // Create a Subscription row
        $subscription = $workspace->subscriptions()->create([
            'plan_id' => $freePlan->id,
            'status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'credits_monthly' => $monthlyCredits,
        ]);

        // Create a WorkspaceUsagePeriod row
        $workspace->usagePeriods()->create([
            'subscription_id' => $subscription->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'credits_limit' => $monthlyCredits,
            'is_current' => true,
        ]);

        // Initialize Redis key for available credits
        try {
            Redis::set("credits:available:{$workspace->id}", $monthlyCredits);
        } catch (\Exception) {
            // Redis not available (e.g., in testing environment)
        }
    }

    /**
     * Update the given workspace.
     *
     * @param  array{name?: string}  $data
     */
    public function update(Workspace $workspace, array $data): Workspace
    {
        if (isset($data['name'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], $workspace->id);
        }

        $workspace->update($data);

        return $workspace;
    }

    /**
     * Delete the given workspace.
     */
    public function delete(Workspace $workspace): void
    {
        $workspace->delete();
    }

    /**
     * Generate a unique slug from the given name.
     */
    private function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        $query = Workspace::query()->where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;

            $query = Workspace::query()->where('slug', $slug);

            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }
}

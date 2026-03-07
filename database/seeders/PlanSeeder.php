<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Uses factory states as the source of truth, then upserts by slug
     * so the seeder is safe to re-run (idempotent).
     */
    public function run(): void
    {
        $descriptions = [
            'free' => 'Perfect for getting started. Build and test your first workflows.',
            'starter' => 'For small teams. Everything you need to scale your automations.',
            'pro' => 'For growing businesses. Advanced features and AI-powered capabilities.',
            'teams' => 'For organizations. Team collaboration with advanced controls.',
            'enterprise' => 'For large enterprises. Unlimited everything with dedicated support.',
        ];

        foreach (['free', 'starter', 'pro', 'teams', 'enterprise'] as $state) {
            $attributes = Plan::factory()->{$state}()->make([
                'description' => $descriptions[$state],
            ])->toArray();

            $slug = $attributes['slug'];
            unset($attributes['slug']);

            Plan::updateOrCreate(['slug' => $slug], $attributes);
        }
    }
}

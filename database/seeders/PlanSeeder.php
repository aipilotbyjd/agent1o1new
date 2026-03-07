<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed all 5 plans using the factory
        Plan::factory()->free()->create([
            'description' => 'Perfect for getting started. Build and test your first workflows.',
        ]);

        Plan::factory()->starter()->create([
            'description' => 'For small teams. Everything you need to scale your automations.',
        ]);

        Plan::factory()->pro()->create([
            'description' => 'For growing businesses. Advanced features and AI-powered capabilities.',
        ]);

        Plan::factory()->teams()->create([
            'description' => 'For organizations. Team collaboration with advanced controls.',
        ]);

        Plan::factory()->enterprise()->create([
            'description' => 'For large enterprises. Unlimited everything with dedicated support.',
        ]);
    }
}

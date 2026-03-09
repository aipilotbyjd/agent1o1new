<?php

namespace Database\Seeders;

use App\Models\NodeCategory;
use Illuminate\Database\Seeder;

class NodeCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Triggers',
                'slug' => 'triggers',
                'description' => 'Starting points for workflows that fire on events or schedules.',
                'icon' => 'bolt',
                'color' => '#F59E0B',
                'sort_order' => 1,
            ],
            [
                'name' => 'AI',
                'slug' => 'ai',
                'description' => 'Artificial intelligence and language model nodes.',
                'icon' => 'cpu-chip',
                'color' => '#8B5CF6',
                'sort_order' => 2,
            ],
            [
                'name' => 'Flow Control',
                'slug' => 'flow-control',
                'description' => 'Nodes that control the execution flow of a workflow.',
                'icon' => 'arrows-right-left',
                'color' => '#3B82F6',
                'sort_order' => 3,
            ],
            [
                'name' => 'Data',
                'slug' => 'data',
                'description' => 'Transform, filter, merge, and manipulate data.',
                'icon' => 'circle-stack',
                'color' => '#10B981',
                'sort_order' => 4,
            ],
            [
                'name' => 'Communication',
                'slug' => 'communication',
                'description' => 'Send messages via email, SMS, or push notifications.',
                'icon' => 'envelope',
                'color' => '#EC4899',
                'sort_order' => 5,
            ],
            [
                'name' => 'HTTP & APIs',
                'slug' => 'http-apis',
                'description' => 'Make HTTP requests and interact with external APIs.',
                'icon' => 'globe-alt',
                'color' => '#F97316',
                'sort_order' => 6,
            ],
            [
                'name' => 'Utility',
                'slug' => 'utility',
                'description' => 'General-purpose helper nodes.',
                'icon' => 'wrench',
                'color' => '#6B7280',
                'sort_order' => 7,
            ],
            [
                'name' => 'Storage',
                'slug' => 'storage',
                'description' => 'Read and write files or cloud storage.',
                'icon' => 'folder',
                'color' => '#0EA5E9',
                'sort_order' => 8,
            ],
        ];

        foreach ($categories as $category) {
            NodeCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category,
            );
        }
    }
}

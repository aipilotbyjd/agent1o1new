<?php

use App\Models\Node;
use App\Models\NodeCategory;
use Database\Seeders\NodeCategorySeeder;
use Database\Seeders\NodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds all node categories', function () {
    $this->seed(NodeCategorySeeder::class);

    expect(NodeCategory::count())->toBe(8)
        ->and(NodeCategory::pluck('slug')->sort()->values()->all())
        ->toBe(['ai', 'communication', 'data', 'flow-control', 'http-apis', 'storage', 'triggers', 'utility']);
});

it('seeds all nodes with correct categories', function () {
    $this->seed(NodeCategorySeeder::class);
    $this->seed(NodeSeeder::class);

    expect(Node::count())->toBe(28);

    $nodesByKind = Node::query()->selectRaw('node_kind, count(*) as cnt')
        ->groupBy('node_kind')
        ->pluck('cnt', 'node_kind');

    expect($nodesByKind->sort()->values()->all())->toBe([3, 6, 19]);
});

it('makes all nodes active by default', function () {
    $this->seed(NodeCategorySeeder::class);
    $this->seed(NodeSeeder::class);

    expect(Node::query()->where('is_active', false)->count())->toBe(0);
});

it('is idempotent when run twice', function () {
    $this->seed(NodeCategorySeeder::class);
    $this->seed(NodeSeeder::class);
    $this->seed(NodeCategorySeeder::class);
    $this->seed(NodeSeeder::class);

    expect(NodeCategory::count())->toBe(8)
        ->and(Node::count())->toBe(28);
});

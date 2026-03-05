<?php

use App\Models\Node;
use App\Models\NodeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Nodes ────────────────────────────────────────────────────

test('authenticated user can list nodes', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->count(3)->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/nodes');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('can search nodes by name', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->create(['category_id' => $category->id, 'name' => 'HTTP Request']);
    Node::factory()->create(['category_id' => $category->id, 'name' => 'Send Email']);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/nodes?search=HTTP');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'HTTP Request');
});

test('can filter nodes by category', function () {
    $user = User::factory()->create();
    $triggers = NodeCategory::factory()->create(['name' => 'Triggers', 'slug' => 'triggers']);
    $actions = NodeCategory::factory()->create(['name' => 'Actions', 'slug' => 'actions']);
    Node::factory()->count(2)->create(['category_id' => $triggers->id]);
    Node::factory()->count(3)->create(['category_id' => $actions->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/v1/nodes?category_id={$triggers->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter nodes by node_kind', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->trigger()->count(2)->create(['category_id' => $category->id]);
    Node::factory()->action()->count(3)->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/nodes?node_kind=trigger');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter nodes by is_premium', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->count(2)->create(['category_id' => $category->id, 'is_premium' => false]);
    Node::factory()->premium()->count(1)->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/nodes?is_premium=true');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('inactive nodes are excluded by default', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->count(2)->create(['category_id' => $category->id]);
    Node::factory()->inactive()->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/nodes');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can view a single node with full schemas', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    $node = Node::factory()->create([
        'category_id' => $category->id,
        'config_schema' => ['properties' => ['url' => ['type' => 'string']]],
        'input_schema' => ['type' => 'object'],
        'output_schema' => ['type' => 'object'],
    ]);

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/v1/nodes/{$node->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $node->id)
        ->assertJsonStructure(['data' => ['config_schema', 'input_schema', 'output_schema', 'category']]);
});

test('unauthenticated user cannot list nodes', function () {
    $response = $this->getJson('/api/v1/nodes');

    $response->assertStatus(401);
});

// ── Categories ───────────────────────────────────────────────

test('authenticated user can list categories', function () {
    $user = User::factory()->create();
    NodeCategory::factory()->count(3)->create();

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/node-categories');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('categories are ordered by sort_order', function () {
    $user = User::factory()->create();
    NodeCategory::factory()->create(['name' => 'Zeta', 'slug' => 'zeta', 'sort_order' => 10]);
    NodeCategory::factory()->create(['name' => 'Alpha', 'slug' => 'alpha', 'sort_order' => 1]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/node-categories');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.1.name', 'Zeta');
});

test('categories include active node count', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->count(3)->create(['category_id' => $category->id]);
    Node::factory()->inactive()->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/node-categories');

    $response->assertOk()
        ->assertJsonPath('data.0.nodes_count', 3);
});

test('can view a category with its nodes', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->count(2)->create(['category_id' => $category->id]);
    Node::factory()->inactive()->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/v1/node-categories/{$category->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $category->id)
        ->assertJsonCount(2, 'data.nodes');
});

test('can include nodes in category listing', function () {
    $user = User::factory()->create();
    $category = NodeCategory::factory()->create();
    Node::factory()->count(2)->create(['category_id' => $category->id]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/node-categories?include_nodes=1');

    $response->assertOk()
        ->assertJsonCount(2, 'data.0.nodes');
});

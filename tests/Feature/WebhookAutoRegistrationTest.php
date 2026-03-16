<?php

use App\Engine\WebhookRegistrars\GitHubWebhookRegistrar;
use App\Engine\WebhookRegistrars\StripeWebhookRegistrar;
use App\Engine\WebhookRegistrars\WebhookRegistrarRegistry;
use App\Enums\Role;
use App\Models\Credential;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Services\WebhookAutoRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupAutoRegWorkspace(string $triggerType = 'stripe', array $triggerConfig = []): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'is_active' => false,
    ]);

    $nodes = [
        [
            'id' => 'trigger_1',
            'type' => 'trigger',
            'position' => ['x' => 0, 'y' => 0],
            'config' => [
                'trigger_type' => $triggerType,
                'events' => ['invoice.paid', 'charge.succeeded'],
                ...$triggerConfig,
            ],
        ],
    ];

    $version = WorkflowVersion::factory()->published()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $owner->id,
        'nodes' => $nodes,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    $credential = Credential::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'type' => $triggerType,
        'data' => json_encode(['secret_key' => 'sk_test_fake123', 'api_key' => 'sk_test_fake123']),
    ]);

    $workflow->credentials()->attach($credential->id, ['node_id' => 'trigger_1']);

    return [$owner, $workspace, $workflow, $credential];
}

// ── WebhookRegistrarRegistry ────────────────────────────────

test('registry resolves stripe registrar', function () {
    $registrar = WebhookRegistrarRegistry::resolve('stripe');

    expect($registrar)
        ->toBeInstanceOf(StripeWebhookRegistrar::class)
        ->and($registrar->provider())->toBe('stripe');
});

test('registry resolves github registrar', function () {
    $registrar = WebhookRegistrarRegistry::resolve('github');

    expect($registrar)
        ->toBeInstanceOf(GitHubWebhookRegistrar::class)
        ->and($registrar->provider())->toBe('github');
});

test('registry returns null for unsupported provider', function () {
    expect(WebhookRegistrarRegistry::resolve('unknown'))->toBeNull();
});

test('registry supports method works', function () {
    expect(WebhookRegistrarRegistry::supports('stripe'))->toBeTrue()
        ->and(WebhookRegistrarRegistry::supports('github'))->toBeTrue()
        ->and(WebhookRegistrarRegistry::supports('unknown'))->toBeFalse();
});

// ── Stripe Signature Verification ───────────────────────────

test('stripe registrar verifies valid signature', function () {
    $registrar = new StripeWebhookRegistrar;
    $payload = '{"type":"invoice.paid"}';
    $secret = 'whsec_test_secret';
    $timestamp = time();
    $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    $signature = "t={$timestamp},v1={$hash}";

    expect($registrar->verifySignature($payload, $signature, $secret))->toBeTrue();
});

test('stripe registrar rejects invalid signature', function () {
    $registrar = new StripeWebhookRegistrar;

    expect($registrar->verifySignature('payload', 't=123,v1=wrong', 'secret'))->toBeFalse();
});

test('stripe registrar rejects expired timestamp', function () {
    $registrar = new StripeWebhookRegistrar;
    $payload = 'test';
    $secret = 'whsec_test';
    $timestamp = time() - 400;
    $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    $signature = "t={$timestamp},v1={$hash}";

    expect($registrar->verifySignature($payload, $signature, $secret))->toBeFalse();
});

// ── GitHub Signature Verification ───────────────────────────

test('github registrar verifies valid signature', function () {
    $registrar = new GitHubWebhookRegistrar;
    $payload = '{"action":"opened"}';
    $secret = 'gh_test_secret';
    $hash = hash_hmac('sha256', $payload, $secret);
    $signature = "sha256={$hash}";

    expect($registrar->verifySignature($payload, $signature, $secret))->toBeTrue();
});

test('github registrar rejects invalid signature', function () {
    $registrar = new GitHubWebhookRegistrar;

    expect($registrar->verifySignature('payload', 'sha256=wrong', 'secret'))->toBeFalse();
});

// ── Auto-Registration Service ───────────────────────────────

test('registerForWorkflow creates external webhook on Stripe', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response([
            'id' => 'we_test_123',
            'secret' => 'whsec_test_secret',
            'status' => 'enabled',
        ], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'webhook_endpoints')
        && $request->method() === 'POST',
    );

    $webhook = $workflow->webhooks()->where('provider', 'stripe')->first();

    expect($webhook)->not->toBeNull()
        ->and($webhook->external_webhook_id)->toBe('we_test_123')
        ->and($webhook->external_webhook_secret)->toBe('whsec_test_secret')
        ->and($webhook->provider)->toBe('stripe')
        ->and($webhook->is_active)->toBeTrue();
});

test('registerForWorkflow creates external webhook on GitHub', function () {
    Http::fake([
        'api.github.com/repos/acme/repo/hooks' => Http::response([
            'id' => 12345,
            'active' => true,
        ], 201),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('github', [
        'owner' => 'acme',
        'repository' => 'repo',
        'events' => ['push', 'pull_request'],
    ]);

    $workflow->credentials()->first()->update([
        'type' => 'github',
        'data' => json_encode(['access_token' => 'ghp_test_token']),
    ]);

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    $webhook = $workflow->webhooks()->where('provider', 'github')->first();

    expect($webhook)->not->toBeNull()
        ->and($webhook->external_webhook_id)->toBe('12345')
        ->and($webhook->provider)->toBe('github')
        ->and($webhook->provider_config)->toMatchArray([
            'owner' => 'acme',
            'repository' => 'repo',
        ]);
});

test('unregisterForWorkflow calls delete on Stripe', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_test_456' => Http::response(['deleted' => true], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');

    Webhook::factory()->create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'node_id' => 'trigger_1',
        'provider' => 'stripe',
        'external_webhook_id' => 'we_test_456',
        'external_webhook_secret' => 'whsec_test',
        'provider_config' => ['node_id' => 'trigger_1'],
    ]);

    $service = app(WebhookAutoRegistrationService::class);
    $service->unregisterForWorkflow($workflow);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'we_test_456')
        && $request->method() === 'DELETE',
    );

    $webhook = $workflow->webhooks()->where('provider', 'stripe')->first();
    expect($webhook->external_webhook_id)->toBeNull();
});

test('registerForWorkflow skips when no credentials found', function () {
    Http::fake();

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');
    $workflow->credentials()->detach();

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    Http::assertNothingSent();
    expect($workflow->webhooks()->where('provider', 'stripe')->count())->toBe(0);
});

test('registerForWorkflow skips non-supported trigger types', function () {
    Http::fake();

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('manual');

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    Http::assertNothingSent();
});

test('registerForWorkflow reuses existing webhook if already registered', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_existing' => Http::response([
            'id' => 'we_existing',
            'status' => 'enabled',
        ], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');

    Webhook::factory()->create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'node_id' => 'trigger_1',
        'provider' => 'stripe',
        'external_webhook_id' => 'we_existing',
        'external_webhook_secret' => 'whsec_exists',
        'provider_config' => ['node_id' => 'trigger_1'],
    ]);

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), 'we_existing'),
    );

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');

    expect($workflow->webhooks()->where('provider', 'stripe')->count())->toBe(1);
});

// ── Workflow Activate/Deactivate Integration ────────────────

test('workflow activate triggers auto-registration', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response([
            'id' => 'we_activate_test',
            'secret' => 'whsec_activate',
            'status' => 'enabled',
        ], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');

    $workflow->activate();

    expect($workflow->fresh()->is_active)->toBeTrue();

    $webhook = $workflow->webhooks()->where('provider', 'stripe')->first();
    expect($webhook)->not->toBeNull()
        ->and($webhook->external_webhook_id)->toBe('we_activate_test');
});

test('workflow deactivate triggers auto-unregistration', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_deactivate' => Http::response(['deleted' => true], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');
    $workflow->update(['is_active' => true]);

    Webhook::factory()->create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'node_id' => 'trigger_1',
        'provider' => 'stripe',
        'external_webhook_id' => 'we_deactivate',
        'external_webhook_secret' => 'whsec_deact',
        'provider_config' => ['node_id' => 'trigger_1'],
    ]);

    $workflow->deactivate();

    expect($workflow->fresh()->is_active)->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'we_deactivate')
        && $request->method() === 'DELETE',
    );
});

// ── Webhook Receiver with Provider Signature ────────────────

test('receiver verifies stripe signature for externally managed webhook', function () {
    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');
    $workflow->update(['is_active' => true]);

    $secret = 'whsec_receiver_test';
    $webhook = Webhook::factory()->create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'node_id' => 'trigger_1',
        'provider' => 'stripe',
        'external_webhook_id' => 'we_receiver',
        'external_webhook_secret' => $secret,
        'provider_config' => ['node_id' => 'trigger_1'],
    ]);

    $payload = json_encode(['type' => 'invoice.paid']);
    $timestamp = time();
    $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    $signature = "t={$timestamp},v1={$hash}";

    $response = $this->call('POST', "/api/v1/webhook/{$webhook->uuid}", [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertSuccessful();
});

test('receiver rejects invalid stripe signature', function () {
    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');
    $workflow->update(['is_active' => true]);

    $webhook = Webhook::factory()->create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'node_id' => 'trigger_1',
        'provider' => 'stripe',
        'external_webhook_id' => 'we_reject',
        'external_webhook_secret' => 'whsec_real_secret',
        'provider_config' => ['node_id' => 'trigger_1'],
    ]);

    $response = $this->call('POST', "/api/v1/webhook/{$webhook->uuid}", [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't=123,v1=invalid_hash',
        'CONTENT_TYPE' => 'application/json',
    ], '{"type":"invoice.paid"}');

    $response->assertStatus(401);
});

// ── Webhook Model ───────────────────────────────────────────

test('webhook isExternallyManaged returns true when provider and external id set', function () {
    $webhook = new Webhook([
        'provider' => 'stripe',
        'external_webhook_id' => 'we_123',
    ]);

    expect($webhook->isExternallyManaged())->toBeTrue();
});

test('webhook isExternallyManaged returns false for manual webhooks', function () {
    $webhook = new Webhook([
        'provider' => null,
        'external_webhook_id' => null,
    ]);

    expect($webhook->isExternallyManaged())->toBeFalse();
});

// ── Regression: Multi-trigger & Coexistence ─────────────────

test('registerForWorkflow creates separate webhooks for multiple trigger nodes of same provider', function () {
    Http::fake([
        'api.github.com/repos/acme/repo-a/hooks' => Http::response(['id' => 111, 'active' => true], 201),
        'api.github.com/repos/acme/repo-b/hooks' => Http::response(['id' => 222, 'active' => true], 201),
    ]);

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => Role::Owner->value, 'joined_at' => now()]);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'is_active' => false,
    ]);

    $nodes = [
        [
            'id' => 'trigger_a',
            'type' => 'trigger',
            'position' => ['x' => 0, 'y' => 0],
            'config' => [
                'trigger_type' => 'github',
                'events' => ['push'],
                'owner' => 'acme',
                'repository' => 'repo-a',
            ],
        ],
        [
            'id' => 'trigger_b',
            'type' => 'trigger',
            'position' => ['x' => 200, 'y' => 0],
            'config' => [
                'trigger_type' => 'github',
                'events' => ['pull_request'],
                'owner' => 'acme',
                'repository' => 'repo-b',
            ],
        ],
    ];

    $version = WorkflowVersion::factory()->published()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $owner->id,
        'nodes' => $nodes,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    $credential = Credential::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'type' => 'github',
        'data' => json_encode(['access_token' => 'ghp_test']),
    ]);

    $workflow->credentials()->attach($credential->id, ['node_id' => 'trigger_a']);
    $workflow->credentials()->attach($credential->id, ['node_id' => 'trigger_b']);

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    $webhooks = $workflow->webhooks()->where('provider', 'github')->get();

    expect($webhooks)->toHaveCount(2)
        ->and($webhooks->pluck('node_id')->sort()->values()->all())->toBe(['trigger_a', 'trigger_b'])
        ->and($webhooks->pluck('external_webhook_id')->sort()->values()->all())->toBe(['111', '222']);
});

test('auto-registered webhook does not block manual webhook creation', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response([
            'id' => 'we_auto',
            'secret' => 'whsec_auto',
            'status' => 'enabled',
        ], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    expect($workflow->webhooks()->whereNotNull('provider')->count())->toBe(1);

    $webhookService = app(\App\Services\WebhookService::class);
    $manualWebhook = $webhookService->create($workspace, $workflow, [
        'methods' => ['POST'],
        'auth_type' => 'none',
        'response_mode' => 'immediate',
    ]);

    expect($manualWebhook)->not->toBeNull()
        ->and($manualWebhook->provider)->toBeNull()
        ->and($workflow->webhooks()->count())->toBe(2);
});

test('auto-registration uses same UUID for callback URL and database row', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response([
            'id' => 'we_uuid_test',
            'secret' => 'whsec_uuid',
            'status' => 'enabled',
        ], 200),
    ]);

    [$owner, $workspace, $workflow] = setupAutoRegWorkspace('stripe');

    $service = app(WebhookAutoRegistrationService::class);
    $service->registerForWorkflow($workflow);

    $webhook = $workflow->webhooks()->where('provider', 'stripe')->first();

    Http::assertSent(function ($request) use ($webhook) {
        if ($request->method() !== 'POST') {
            return false;
        }

        $sentUrl = $request->data()['url'] ?? '';

        return str_contains($sentUrl, $webhook->uuid);
    });
});

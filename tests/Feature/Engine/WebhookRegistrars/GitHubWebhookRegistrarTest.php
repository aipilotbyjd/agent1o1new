<?php

use App\Engine\WebhookRegistrars\GitHubWebhookRegistrar;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->registrar = new GitHubWebhookRegistrar;

    $this->credentials = ['access_token' => 'test-token'];

    $this->providerConfig = [
        'owner' => 'acme',
        'repository' => 'widgets',
    ];
});

// ── provider ────────────────────────────────────────────────

it('returns github as the provider name', function () {
    expect($this->registrar->provider())->toBe('github');
});

// ── checkExists ─────────────────────────────────────────────

it('returns true when the webhook exists', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks/12345' => Http::response([], 200),
    ]);

    expect($this->registrar->checkExists('12345', $this->credentials, $this->providerConfig))
        ->toBeTrue();
});

it('returns false when the webhook does not exist', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks/99999' => Http::response([], 404),
    ]);

    expect($this->registrar->checkExists('99999', $this->credentials, $this->providerConfig))
        ->toBeFalse();
});

// ── register ────────────────────────────────────────────────

it('registers a webhook and returns external_id and secret', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks' => Http::response(['id' => 42], 201),
    ]);

    $result = $this->registrar->register(
        'https://example.com/webhook',
        ['push', 'pull_request'],
        $this->credentials,
        $this->providerConfig,
    );

    expect($result)
        ->toHaveKeys(['external_id', 'secret'])
        ->external_id->toBe('42')
        ->secret->toHaveLength(64);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/acme/widgets/hooks'
            && $request->method() === 'POST'
            && $request['name'] === 'web'
            && $request['config']['url'] === 'https://example.com/webhook'
            && $request['config']['content_type'] === 'json'
            && $request['events'] === ['push', 'pull_request']
            && $request['active'] === true;
    });
});

it('finds an existing webhook on 422 conflict', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks' => Http::sequence()
            ->push([], 422)
            ->push([
                ['id' => 7, 'config' => ['url' => 'https://other.com/hook']],
                ['id' => 8, 'config' => ['url' => 'https://example.com/webhook']],
            ], 200),
    ]);

    $result = $this->registrar->register(
        'https://example.com/webhook',
        ['push'],
        $this->credentials,
        $this->providerConfig,
    );

    expect($result)
        ->external_id->toBe('8')
        ->secret->toHaveLength(64);
});

it('throws when 422 and no matching webhook found', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks' => Http::sequence()
            ->push([], 422)
            ->push([
                ['id' => 7, 'config' => ['url' => 'https://other.com/hook']],
            ], 200),
    ]);

    $this->registrar->register(
        'https://example.com/webhook',
        ['push'],
        $this->credentials,
        $this->providerConfig,
    );
})->throws(RuntimeException::class, 'Webhook already exists but could not be found');

// ── unregister ──────────────────────────────────────────────

it('deletes a webhook', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks/12345' => Http::response([], 204),
    ]);

    $this->registrar->unregister('12345', $this->credentials, $this->providerConfig);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/acme/widgets/hooks/12345'
            && $request->method() === 'DELETE';
    });
});

it('swallows 404 when unregistering a missing webhook', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks/99999' => Http::response([], 404),
    ]);

    $this->registrar->unregister('99999', $this->credentials, $this->providerConfig);

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE';
    });
});

// ── verifySignature ─────────────────────────────────────────

it('verifies a valid signature', function () {
    $secret = 'my-secret';
    $payload = '{"action":"opened"}';
    $hash = hash_hmac('sha256', $payload, $secret);
    $signature = "sha256={$hash}";

    expect($this->registrar->verifySignature($payload, $signature, $secret))
        ->toBeTrue();
});

it('rejects an invalid signature', function () {
    expect($this->registrar->verifySignature('payload', 'sha256=invalid', 'secret'))
        ->toBeFalse();
});

it('uses token key when access_token is not available', function () {
    Http::fake([
        'api.github.com/repos/acme/widgets/hooks/1' => Http::response([], 200),
    ]);

    $credentials = ['token' => 'fallback-token'];

    $this->registrar->checkExists('1', $credentials, $this->providerConfig);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer fallback-token');
    });
});

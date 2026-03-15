<?php

use App\Engine\WebhookRegistrars\StripeWebhookRegistrar;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->registrar = new StripeWebhookRegistrar;
    $this->credentials = ['secret_key' => 'sk_test_abc123'];
});

test('provider returns stripe', function () {
    expect($this->registrar->provider())->toBe('stripe');
});

test('checkExists returns true when webhook exists', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_123' => Http::response(['id' => 'we_123'], 200),
    ]);

    expect($this->registrar->checkExists('we_123', $this->credentials))->toBeTrue();
});

test('checkExists returns false when webhook does not exist', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_missing' => Http::response(['error' => ['type' => 'invalid_request_error']], 404),
    ]);

    expect($this->registrar->checkExists('we_missing', $this->credentials))->toBeFalse();
});

test('register creates a webhook endpoint and returns external_id and secret', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response([
            'id' => 'we_new_456',
            'secret' => 'whsec_test_secret',
        ], 200),
    ]);

    $result = $this->registrar->register(
        'https://example.com/webhook',
        ['charge.succeeded', 'invoice.paid'],
        $this->credentials,
    );

    expect($result)->toBe([
        'external_id' => 'we_new_456',
        'secret' => 'whsec_test_secret',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stripe.com/v1/webhook_endpoints'
            && $request->method() === 'POST'
            && str_contains($request->body(), 'url=https');
    });
});

test('register throws on failure', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response(['error' => ['message' => 'Invalid']], 400),
    ]);

    $this->registrar->register(
        'https://example.com/webhook',
        ['charge.succeeded'],
        $this->credentials,
    );
})->throws(\Illuminate\Http\Client\RequestException::class);

test('unregister deletes the webhook endpoint', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_123' => Http::response(['id' => 'we_123', 'deleted' => true], 200),
    ]);

    $this->registrar->unregister('we_123', $this->credentials);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stripe.com/v1/webhook_endpoints/we_123'
            && $request->method() === 'DELETE';
    });
});

test('unregister silently ignores 404 errors', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_gone' => Http::response(['error' => ['type' => 'invalid_request_error']], 404),
    ]);

    $this->registrar->unregister('we_gone', $this->credentials);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'we_gone');
    });
});

test('unregister throws on non-404 errors', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_123' => Http::response(['error' => ['message' => 'Server error']], 500),
    ]);

    $this->registrar->unregister('we_123', $this->credentials);
})->throws(\Illuminate\Http\Client\RequestException::class);

test('verifySignature returns true for valid signature', function () {
    $secret = 'whsec_test_secret';
    $payload = '{"id":"evt_123"}';
    $timestamp = (string) time();
    $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    $signature = "t={$timestamp},v1={$hash}";

    expect($this->registrar->verifySignature($payload, $signature, $secret))->toBeTrue();
});

test('verifySignature returns false for invalid hash', function () {
    $timestamp = (string) time();
    $signature = "t={$timestamp},v1=invalidhash";

    expect($this->registrar->verifySignature('{"id":"evt_123"}', $signature, 'whsec_secret'))->toBeFalse();
});

test('verifySignature returns false for expired timestamp', function () {
    $secret = 'whsec_test_secret';
    $payload = '{"id":"evt_123"}';
    $timestamp = (string) (time() - 400);
    $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    $signature = "t={$timestamp},v1={$hash}";

    expect($this->registrar->verifySignature($payload, $signature, $secret))->toBeFalse();
});

test('verifySignature returns false for missing signature parts', function () {
    expect($this->registrar->verifySignature('payload', 'invalid', 'secret'))->toBeFalse();
});

test('checkExists uses api_key fallback from credentials', function () {
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints/we_123' => Http::response(['id' => 'we_123'], 200),
    ]);

    $result = $this->registrar->checkExists('we_123', ['api_key' => 'sk_alt_key']);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->header('Authorization')[0] ?? '', 'sk_alt_key');
    });
});

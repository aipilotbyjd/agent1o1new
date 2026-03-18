<?php

use App\Engine\Nodes\Apps\Stripe\StripeNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('creates a customer', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response([
            'id' => 'cus_123',
            'object' => 'customer',
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ], 200),
    ]);

    $node = new StripeNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'stripe',
        nodeName: 'Stripe',
        config: ['operation' => 'create_customer'],
        inputData: [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
            'description' => 'VIP customer',
            'metadata' => ['tier' => 'gold'],
        ],
        credentials: ['secret_key' => 'sk_test_123']
    );

    $result = $node->handle($payload);

    expect($result->output)
        ->toHaveKey('id', 'cus_123')
        ->toHaveKey('email', 'jane@example.com');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'api.stripe.com/v1/customers')
            && $request->hasHeader('Stripe-Version', '2024-12-18.acacia')
            && str_contains($request->body(), 'email=jane%40example.com');
    });
});

it('creates an invoice', function () {
    Http::fake([
        'api.stripe.com/v1/invoices' => Http::response([
            'id' => 'in_123',
            'object' => 'invoice',
            'customer' => 'cus_123',
            'auto_advance' => true,
        ], 200),
    ]);

    $node = new StripeNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'stripe',
        nodeName: 'Stripe',
        config: [
            'operation' => 'create_invoice',
            'customer' => 'cus_123',
            'description' => 'Monthly invoice',
        ],
        inputData: [],
        credentials: ['secret_key' => 'sk_test_123']
    );

    $result = $node->handle($payload);

    expect($result->output)
        ->toHaveKey('id', 'in_123')
        ->toHaveKey('customer', 'cus_123');
});

it('lists payment intents', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents*' => Http::response([
            'data' => [
                ['id' => 'pi_1', 'amount' => 2000],
                ['id' => 'pi_2', 'amount' => 5000],
            ],
            'has_more' => false,
        ], 200),
    ]);

    $node = new StripeNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'stripe',
        nodeName: 'Stripe',
        config: [
            'operation' => 'list_payments',
            'limit' => 5,
            'customer' => 'cus_123',
        ],
        inputData: [],
        credentials: ['api_key' => 'sk_test_123']
    );

    $result = $node->handle($payload);

    expect($result->output['payments'])->toHaveCount(2)
        ->and($result->output['has_more'])->toBeFalse();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), 'limit=5')
            && str_contains($request->url(), 'customer=cus_123');
    });
});

it('creates a charge', function () {
    Http::fake([
        'api.stripe.com/v1/charges' => Http::response([
            'id' => 'ch_123',
            'object' => 'charge',
            'amount' => 1500,
            'currency' => 'usd',
        ], 200),
    ]);

    $node = new StripeNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'stripe',
        nodeName: 'Stripe',
        config: ['operation' => 'create_charge'],
        inputData: [
            'amount' => 1500,
            'currency' => 'usd',
            'source' => 'tok_visa',
            'description' => 'Test charge',
        ],
        credentials: ['secret_key' => 'sk_test_123']
    );

    $result = $node->handle($payload);

    expect($result->output)
        ->toHaveKey('id', 'ch_123')
        ->toHaveKey('amount', 1500);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->body(), 'amount=1500')
            && str_contains($request->body(), 'currency=usd')
            && str_contains($request->body(), 'source=tok_visa');
    });
});

it('gets the account balance', function () {
    Http::fake([
        'api.stripe.com/v1/balance' => Http::response([
            'object' => 'balance',
            'available' => [['amount' => 10000, 'currency' => 'usd']],
            'pending' => [['amount' => 5000, 'currency' => 'usd']],
        ], 200),
    ]);

    $node = new StripeNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'stripe',
        nodeName: 'Stripe',
        config: ['operation' => 'get_balance'],
        inputData: [],
        credentials: ['secret_key' => 'sk_test_123']
    );

    $result = $node->handle($payload);

    expect($result->output)
        ->toHaveKey('object', 'balance')
        ->toHaveKey('available')
        ->toHaveKey('pending');
});

it('returns an error for unknown operations', function () {
    $node = new StripeNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'stripe',
        nodeName: 'Stripe',
        config: ['operation' => 'invalid_op'],
        inputData: [],
        credentials: ['secret_key' => 'sk_test_123']
    );

    $result = $node->handle($payload);

    expect($result->output)->toBeNull()
        ->and($result->error['code'])->toBe('STRIPE_ERROR');
});

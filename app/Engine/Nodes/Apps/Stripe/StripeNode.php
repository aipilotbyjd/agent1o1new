<?php

namespace App\Engine\Nodes\Apps\Stripe;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class StripeNode extends AppNode
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    protected function errorCode(): string
    {
        return 'STRIPE_ERROR';
    }

    protected function operations(): array
    {
        return [
            'create_customer' => $this->createCustomer(...),
            'create_invoice' => $this->createInvoice(...),
            'list_payments' => $this->listPayments(...),
            'create_charge' => $this->createCharge(...),
            'get_balance' => $this->getBalance(...),
        ];
    }

    private function client(NodePayload $payload): PendingRequest
    {
        $secretKey = $payload->credentials['secret_key'] ?? $payload->credentials['api_key'] ?? '';

        return Http::timeout(30)
            ->withToken($secretKey)
            ->withHeaders(['Stripe-Version' => '2024-12-18.acacia']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createCustomer(NodePayload $payload): array
    {
        $config = $payload->config;
        $data = array_filter([
            'email' => $payload->inputData['email'] ?? $config['email'] ?? null,
            'name' => $payload->inputData['name'] ?? $config['name'] ?? null,
            'description' => $payload->inputData['description'] ?? $config['description'] ?? null,
        ]);

        $metadata = $payload->inputData['metadata'] ?? $config['metadata'] ?? [];
        foreach ($metadata as $key => $value) {
            $data["metadata[{$key}]"] = $value;
        }

        $response = $this->client($payload)
            ->asForm()
            ->post(self::BASE_URL.'/customers', $data);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function createInvoice(NodePayload $payload): array
    {
        $config = $payload->config;

        $data = array_filter([
            'customer' => $payload->inputData['customer'] ?? $config['customer'] ?? null,
            'description' => $payload->inputData['description'] ?? $config['description'] ?? null,
        ]);

        $data['auto_advance'] = $payload->inputData['auto_advance'] ?? $config['auto_advance'] ?? true;

        $response = $this->client($payload)
            ->asForm()
            ->post(self::BASE_URL.'/invoices', $data);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayments(NodePayload $payload): array
    {
        $config = $payload->config;

        $params = [
            'limit' => (int) ($config['limit'] ?? 10),
        ];

        $customer = $config['customer'] ?? null;
        if ($customer) {
            $params['customer'] = $customer;
        }

        $response = $this->client($payload)
            ->get(self::BASE_URL.'/payment_intents', $params);

        $response->throw();

        return [
            'payments' => $response->json('data', []),
            'has_more' => $response->json('has_more', false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createCharge(NodePayload $payload): array
    {
        $config = $payload->config;

        $data = array_filter([
            'amount' => (int) ($payload->inputData['amount'] ?? $config['amount'] ?? 0),
            'currency' => $payload->inputData['currency'] ?? $config['currency'] ?? 'usd',
            'source' => $payload->inputData['source'] ?? $config['source'] ?? null,
            'description' => $payload->inputData['description'] ?? $config['description'] ?? null,
        ]);

        $response = $this->client($payload)
            ->asForm()
            ->post(self::BASE_URL.'/charges', $data);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function getBalance(NodePayload $payload): array
    {
        $response = $this->client($payload)
            ->get(self::BASE_URL.'/balance');

        $response->throw();

        return $response->json();
    }
}

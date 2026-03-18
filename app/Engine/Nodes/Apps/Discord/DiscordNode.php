<?php

namespace App\Engine\Nodes\Apps\Discord;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

class DiscordNode extends AppNode
{
    protected function errorCode(): string
    {
        return 'DISCORD_ERROR';
    }

    protected function operations(): array
    {
        return [
            'send_message' => $this->sendMessage(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMessage(NodePayload $payload): array
    {
        $config = $payload->config;
        $webhookUrl = $config['webhook_url'] ?? '';
        $username = $config['username'] ?? null;
        $content = $payload->inputData['content'] ?? $config['content'] ?? '';

        $body = ['content' => $content];

        if ($username) {
            $body['username'] = $username;
        }

        $response = Http::timeout(30)->post($webhookUrl, $body);

        $response->throw();

        $json = $response->json();

        return $json ?: ['sent' => true];
    }
}

<?php

namespace App\Engine\Nodes\Apps\Slack;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

class SlackNode extends AppNode
{
    private const BASE_URL = 'https://slack.com/api';

    protected function errorCode(): string
    {
        return 'SLACK_ERROR';
    }

    protected function operations(): array
    {
        return [
            'send_message' => $this->sendMessage(...),
            'list_channels' => $this->listChannels(...),
            'list_users' => $this->listUsers(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMessage(NodePayload $payload): array
    {
        $config = $payload->config;
        $channel = $config['channel'] ?? '';
        $message = $payload->inputData['message'] ?? $config['message'] ?? '';

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL.'/chat.postMessage', [
                'channel' => $channel,
                'text' => $message,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function listChannels(NodePayload $payload): array
    {
        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL.'/conversations.list', [
                'exclude_archived' => true,
                'types' => 'public_channel,private_channel',
            ]);

        $response->throw();

        return [
            'channels' => $response->json('channels', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listUsers(NodePayload $payload): array
    {
        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL.'/users.list');

        $response->throw();

        return [
            'members' => $response->json('members', []),
        ];
    }
}

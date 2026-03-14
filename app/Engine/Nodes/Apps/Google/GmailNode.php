<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

/**
 * Handles Gmail operations: send_email, add_label, list_messages.
 */
class GmailNode extends AppNode
{
    private const BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    protected function errorCode(): string
    {
        return 'GMAIL_ERROR';
    }

    protected function operations(): array
    {
        return [
            'send_email' => $this->sendEmail(...),
            'add_label' => $this->addLabel(...),
            'list_messages' => $this->listMessages(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendEmail(NodePayload $payload): array
    {
        $config = $payload->config;
        $to = $payload->inputData['to'] ?? $config['to'];
        $subject = $payload->inputData['subject'] ?? $config['subject'] ?? '';
        $body = $payload->inputData['body'] ?? $config['body'] ?? '';
        $cc = $payload->inputData['cc'] ?? $config['cc'] ?? null;
        $bcc = $payload->inputData['bcc'] ?? $config['bcc'] ?? null;

        $rawMessage = "To: {$to}\r\n";
        if ($cc) {
            $rawMessage .= "Cc: {$cc}\r\n";
        }
        if ($bcc) {
            $rawMessage .= "Bcc: {$bcc}\r\n";
        }
        $rawMessage .= "Subject: {$subject}\r\n";
        $rawMessage .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $rawMessage .= $body;

        $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL.'/messages/send', [
                'raw' => $encoded,
            ]);

        $response->throw();

        return [
            'message_id' => $response->json('id'),
            'thread_id' => $response->json('threadId'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addLabel(NodePayload $payload): array
    {
        $messageId = $payload->inputData['message_id'] ?? $payload->config['message_id'];
        $labelIds = (array) ($payload->inputData['label_ids'] ?? $payload->config['label_ids'] ?? []);

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/messages/{$messageId}/modify", [
                'addLabelIds' => $labelIds,
            ]);

        $response->throw();

        return [
            'message_id' => $response->json('id'),
            'label_ids' => $response->json('labelIds', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listMessages(NodePayload $payload): array
    {
        $config = $payload->config;
        $query = $payload->inputData['query'] ?? $config['query'] ?? '';
        $maxResults = $config['max_results'] ?? 10;

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL.'/messages', [
                'q' => $query,
                'maxResults' => $maxResults,
            ]);

        $response->throw();

        return [
            'messages' => $response->json('messages', []),
            'result_count' => count($response->json('messages', [])),
        ];
    }
}

<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Nodes\Concerns\ResolvesCredentials;
use App\Engine\Runners\NodePayload;

/**
 * Handles Gmail operations: send_email, add_label, list_messages.
 */
class GmailNode implements NodeHandler
{
    use ResolvesCredentials;

    private const BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        try {
            $operation = $payload->config['operation'] ?? 'send_email';

            $result = match ($operation) {
                'send_email' => $this->sendEmail($payload),
                'add_label' => $this->addLabel($payload),
                'list_messages' => $this->listMessages($payload),
                default => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
            };

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::completed($result, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed($e->getMessage(), 'GMAIL_ERROR', $durationMs);
        }
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

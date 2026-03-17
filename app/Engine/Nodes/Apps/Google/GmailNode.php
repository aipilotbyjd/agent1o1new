<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

/**
 * Handles Gmail operations: send_email, reply_to_message, get_message, modify_message, add_label, list_messages, list_labels, delete_message.
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
            'reply_to_message' => $this->replyToMessage(...),
            'get_message' => $this->getMessage(...),
            'modify_message' => $this->modifyMessage(...),
            'add_label' => $this->addLabel(...),
            'list_messages' => $this->listMessages(...),
            'list_labels' => $this->listLabels(...),
            'delete_message' => $this->deleteMessage(...),
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

    /**
     * @return array<string, mixed>
     */
    private function getMessage(NodePayload $payload): array
    {
        $messageId = $payload->inputData['message_id'] ?? $payload->config['message_id'];
        $format = $payload->config['format'] ?? 'full';

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL."/messages/{$messageId}", [
                'format' => $format,
            ]);

        $response->throw();

        $data = $response->json();
        $headers = collect($data['payload']['headers'] ?? []);

        return [
            'message_id' => $data['id'],
            'thread_id' => $data['threadId'],
            'subject' => $headers->firstWhere('name', 'Subject')['value'] ?? '',
            'from' => $headers->firstWhere('name', 'From')['value'] ?? '',
            'date' => $headers->firstWhere('name', 'Date')['value'] ?? '',
            'snippet' => $data['snippet'] ?? '',
            'label_ids' => $data['labelIds'] ?? [],
            'body' => $this->extractBody($data['payload'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBody(array $payload): string
    {
        $parts = $payload['parts'] ?? [];

        foreach ($parts as $part) {
            if (in_array($part['mimeType'] ?? '', ['text/plain', 'text/html'], true)) {
                $data = $part['body']['data'] ?? '';

                return base64_decode(strtr($data, '-_', '+/'));
            }
        }

        $data = $payload['body']['data'] ?? '';

        if ($data !== '') {
            return base64_decode(strtr($data, '-_', '+/'));
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function replyToMessage(NodePayload $payload): array
    {
        $config = $payload->config;
        $messageId = $payload->inputData['message_id'] ?? $config['message_id'];
        $to = $payload->inputData['to'] ?? $config['to'];
        $body = $payload->inputData['body'] ?? $config['body'] ?? '';

        $originalResponse = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL."/messages/{$messageId}", [
                'format' => 'metadata',
            ]);

        $originalResponse->throw();

        $originalData = $originalResponse->json();
        $threadId = $originalData['threadId'];
        $headers = collect($originalData['payload']['headers'] ?? []);
        $subject = $headers->firstWhere('name', 'Subject')['value'] ?? '';
        $originalMessageIdHeader = $headers->firstWhere('name', 'Message-ID')['value'] ?? '';

        if (! str_starts_with($subject, 'Re: ')) {
            $subject = "Re: {$subject}";
        }

        $rawMessage = "To: {$to}\r\n";
        $rawMessage .= "Subject: {$subject}\r\n";
        if ($originalMessageIdHeader) {
            $rawMessage .= "In-Reply-To: {$originalMessageIdHeader}\r\n";
            $rawMessage .= "References: {$originalMessageIdHeader}\r\n";
        }
        $rawMessage .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $rawMessage .= $body;

        $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL.'/messages/send', [
                'raw' => $encoded,
                'threadId' => $threadId,
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
    private function modifyMessage(NodePayload $payload): array
    {
        $config = $payload->config;
        $messageId = $payload->inputData['message_id'] ?? $config['message_id'];
        $addLabelIds = (array) ($config['add_label_ids'] ?? []);
        $removeLabelIds = (array) ($config['remove_label_ids'] ?? []);

        if (! empty($config['mark_read'])) {
            $removeLabelIds[] = 'UNREAD';
        }

        if (! empty($config['mark_unread'])) {
            $addLabelIds[] = 'UNREAD';
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/messages/{$messageId}/modify", [
                'addLabelIds' => $addLabelIds,
                'removeLabelIds' => $removeLabelIds,
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
    private function listLabels(NodePayload $payload): array
    {
        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL.'/labels');

        $response->throw();

        $labels = collect($response->json('labels', []))->map(fn (array $label) => [
            'id' => $label['id'],
            'name' => $label['name'],
            'type' => $label['type'] ?? '',
        ])->all();

        return [
            'labels' => $labels,
            'label_count' => count($labels),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteMessage(NodePayload $payload): array
    {
        $messageId = $payload->inputData['message_id'] ?? $payload->config['message_id'];

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/messages/{$messageId}/trash");

        $response->throw();

        return [
            'message_id' => $response->json('id'),
            'trashed' => true,
        ];
    }
}

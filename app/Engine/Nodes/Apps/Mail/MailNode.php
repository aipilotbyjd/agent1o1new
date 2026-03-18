<?php

namespace App\Engine\Nodes\Apps\Mail;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail as MailFacade;

class MailNode extends AppNode
{
    protected function errorCode(): string
    {
        return 'MAIL_ERROR';
    }

    protected function operations(): array
    {
        return [
            'send_email' => $this->sendEmail(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendEmail(NodePayload $payload): array
    {
        $config = $payload->config;
        $to = $config['to'] ?? '';
        $subject = $config['subject'] ?? '';
        $bodyType = $config['body_type'] ?? 'html';
        $body = $payload->inputData['body'] ?? $config['body'] ?? '';
        $cc = $payload->inputData['cc'] ?? $config['cc'] ?? null;
        $bcc = $payload->inputData['bcc'] ?? $config['bcc'] ?? null;

        $sentMessage = MailFacade::raw($bodyType === 'text' ? $body : '', function (Message $message) use ($to, $subject, $body, $cc, $bcc, $bodyType) {
            $message->to($to)->subject($subject);

            if ($bodyType === 'html') {
                $message->html($body);
            }

            if ($cc) {
                $message->cc($cc);
            }

            if ($bcc) {
                $message->bcc($bcc);
            }
        });

        return [
            'sent' => true,
            'message_id' => $sentMessage?->getMessageId(),
        ];
    }
}

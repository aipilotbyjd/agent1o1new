<?php

use App\Engine\Nodes\Apps\Mail\MailNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Mail;

it('sends an html email', function () {
    Mail::fake();

    $node = new MailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'mail',
        nodeName: 'Mail',
        config: [
            'operation' => 'send_email',
            'to' => 'user@example.com',
            'subject' => 'Test Subject',
            'body_type' => 'html',
        ],
        inputData: ['body' => '<h1>Hello</h1>'],
    );

    $result = $node->handle($payload);

    expect($result->output)->toHaveKey('sent', true)
        ->and($result->isSuccessful())->toBeTrue();
});

it('sends a text email with cc and bcc', function () {
    Mail::fake();

    $node = new MailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'mail',
        nodeName: 'Mail',
        config: [
            'operation' => 'send_email',
            'to' => 'user@example.com',
            'subject' => 'Plain Text',
            'body_type' => 'text',
        ],
        inputData: [
            'body' => 'Hello plain text',
            'cc' => 'cc@example.com',
            'bcc' => 'bcc@example.com',
        ],
    );

    $result = $node->handle($payload);

    expect($result->output)->toHaveKey('sent', true)
        ->and($result->isSuccessful())->toBeTrue();
});

it('returns an error for unknown operations', function () {
    $node = new MailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'mail',
        nodeName: 'Mail',
        config: ['operation' => 'invalid_op'],
        inputData: [],
    );

    $result = $node->handle($payload);

    expect($result->output)->toBeNull()
        ->and($result->error['code'])->toBe('MAIL_ERROR');
});

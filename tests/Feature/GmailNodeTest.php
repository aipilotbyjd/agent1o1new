<?php

use App\Engine\Nodes\Apps\Google\GmailNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('sends an email via gmail', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/send*' => Http::response([
            'id' => 'msg_123',
            'threadId' => 'thread_123',
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'send_email',
            'to' => 'test@example.com',
            'subject' => 'Hello',
            'body' => 'World',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'message_id' => 'msg_123',
        'thread_id' => 'thread_123',
    ]);
});

it('adds a label to an email', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/msg_123/modify*' => Http::response([
            'id' => 'msg_123',
            'labelIds' => ['Label_1', 'Label_2'],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'add_label',
            'message_id' => 'msg_123',
            'label_ids' => ['Label_1'],
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'message_id' => 'msg_123',
        'label_ids' => ['Label_1', 'Label_2'],
    ]);
});

it('lists messages', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
            'messages' => [
                ['id' => 'msg_1', 'threadId' => 'thread_1'],
                ['id' => 'msg_2', 'threadId' => 'thread_2'],
            ],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'list_messages',
            'query' => 'is:unread',
            'max_results' => 10,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['result_count'])->toBe(2)
        ->and($result['messages'])->toHaveCount(2);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'q=is%3Aunread')
            && str_contains($request->url(), 'maxResults=10');
    });
});

it('gets a message with decoded body from payload parts', function () {
    $bodyContent = '<p>Hello World</p>';
    $encodedBody = rtrim(strtr(base64_encode($bodyContent), '+/', '-_'), '=');

    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/msg_456*' => Http::response([
            'id' => 'msg_456',
            'threadId' => 'thread_456',
            'snippet' => 'Hello World',
            'labelIds' => ['INBOX', 'UNREAD'],
            'payload' => [
                'headers' => [
                    ['name' => 'Subject', 'value' => 'Test Subject'],
                    ['name' => 'From', 'value' => 'sender@example.com'],
                    ['name' => 'Date', 'value' => 'Mon, 1 Jan 2024 00:00:00 +0000'],
                ],
                'parts' => [
                    [
                        'mimeType' => 'text/html',
                        'body' => ['data' => $encodedBody],
                    ],
                ],
            ],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'get_message',
            'message_id' => 'msg_456',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'message_id' => 'msg_456',
        'thread_id' => 'thread_456',
        'subject' => 'Test Subject',
        'from' => 'sender@example.com',
        'date' => 'Mon, 1 Jan 2024 00:00:00 +0000',
        'snippet' => 'Hello World',
        'label_ids' => ['INBOX', 'UNREAD'],
        'body' => $bodyContent,
    ]);
});

it('gets a message with body from payload body data directly', function () {
    $bodyContent = 'Plain text body';
    $encodedBody = rtrim(strtr(base64_encode($bodyContent), '+/', '-_'), '=');

    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/msg_789*' => Http::response([
            'id' => 'msg_789',
            'threadId' => 'thread_789',
            'snippet' => 'Plain text body',
            'labelIds' => ['INBOX'],
            'payload' => [
                'headers' => [
                    ['name' => 'Subject', 'value' => 'Simple Email'],
                    ['name' => 'From', 'value' => 'bob@example.com'],
                    ['name' => 'Date', 'value' => 'Tue, 2 Jan 2024 12:00:00 +0000'],
                ],
                'body' => ['data' => $encodedBody],
            ],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'get_message',
            'message_id' => 'msg_789',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['body'])->toBe($bodyContent);
});

it('replies to a message', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/msg_original*' => Http::response([
            'id' => 'msg_original',
            'threadId' => 'thread_original',
            'payload' => [
                'headers' => [
                    ['name' => 'Subject', 'value' => 'Original Subject'],
                    ['name' => 'Message-ID', 'value' => '<original@example.com>'],
                ],
            ],
        ], 200),
        'gmail.googleapis.com/gmail/v1/users/me/messages/send*' => Http::response([
            'id' => 'msg_reply',
            'threadId' => 'thread_original',
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'reply_to_message',
            'message_id' => 'msg_original',
            'to' => 'recipient@example.com',
            'body' => '<p>Reply body</p>',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'message_id' => 'msg_reply',
        'thread_id' => 'thread_original',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        if ($request->method() !== 'POST') {
            return false;
        }

        $body = json_decode($request->body(), true);
        if (! isset($body['threadId'], $body['raw'])) {
            return false;
        }

        $decoded = base64_decode(strtr($body['raw'], '-_', '+/'));

        return str_contains($decoded, 'Re: Original Subject')
            && str_contains($decoded, 'In-Reply-To: <original@example.com>')
            && str_contains($decoded, 'References: <original@example.com>')
            && $body['threadId'] === 'thread_original';
    });
});

it('modifies a message with label changes and mark_read', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/msg_mod/modify*' => Http::response([
            'id' => 'msg_mod',
            'labelIds' => ['INBOX', 'Label_1'],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'modify_message',
            'message_id' => 'msg_mod',
            'add_label_ids' => ['Label_1'],
            'remove_label_ids' => ['SPAM'],
            'mark_read' => true,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'message_id' => 'msg_mod',
        'label_ids' => ['INBOX', 'Label_1'],
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        if ($request->method() !== 'POST') {
            return false;
        }

        $body = json_decode($request->body(), true);

        return in_array('Label_1', $body['addLabelIds'] ?? [])
            && in_array('SPAM', $body['removeLabelIds'] ?? [])
            && in_array('UNREAD', $body['removeLabelIds'] ?? []);
    });
});

it('lists all labels', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/labels*' => Http::response([
            'labels' => [
                ['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'],
                ['id' => 'Label_1', 'name' => 'Work', 'type' => 'user'],
                ['id' => 'Label_2', 'name' => 'Personal', 'type' => 'user'],
            ],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'list_labels',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['label_count'])->toBe(3)
        ->and($result['labels'])->toHaveCount(3)
        ->and($result['labels'][0])->toBe(['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system']);
});

it('deletes a message by trashing it', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/msg_del/trash*' => Http::response([
            'id' => 'msg_del',
            'labelIds' => ['TRASH'],
        ], 200),
    ]);

    $node = new GmailNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'gmail',
        nodeName: 'Gmail',
        config: [
            'operation' => 'delete_message',
            'message_id' => 'msg_del',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'message_id' => 'msg_del',
        'trashed' => true,
    ]);
});

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

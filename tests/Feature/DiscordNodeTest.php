<?php

use App\Engine\Nodes\Apps\Discord\DiscordNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('sends a message via webhook', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(null, 204),
    ]);

    $node = new DiscordNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'discord',
        nodeName: 'Discord',
        config: [
            'operation' => 'send_message',
            'webhook_url' => 'https://discord.com/api/webhooks/123/abc',
            'username' => 'TestBot',
        ],
        inputData: ['content' => 'Hello from test!'],
    );

    $result = $node->handle($payload);

    expect($result->output)->toHaveKey('sent', true);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'discord.com/api/webhooks/123/abc')
            && $request['content'] === 'Hello from test!'
            && $request['username'] === 'TestBot';
    });
});

it('sends a message without optional username', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(['id' => '999'], 200),
    ]);

    $node = new DiscordNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'discord',
        nodeName: 'Discord',
        config: [
            'operation' => 'send_message',
            'webhook_url' => 'https://discord.com/api/webhooks/123/abc',
        ],
        inputData: ['content' => 'No username'],
    );

    $result = $node->handle($payload);

    expect($result->output)->toHaveKey('id', '999');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request['content'] === 'No username'
            && ! isset($request['username']);
    });
});

it('returns an error for unknown operations', function () {
    $node = new DiscordNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'discord',
        nodeName: 'Discord',
        config: ['operation' => 'invalid_op'],
        inputData: [],
    );

    $result = $node->handle($payload);

    expect($result->output)->toBeNull()
        ->and($result->error['code'])->toBe('DISCORD_ERROR');
});

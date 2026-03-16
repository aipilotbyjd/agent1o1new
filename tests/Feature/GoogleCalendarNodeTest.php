<?php

use App\Engine\Nodes\Apps\Google\GoogleCalendarNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('lists events', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [
                ['id' => 'event_1', 'summary' => 'Meeting'],
            ],
        ], 200),
    ]);

    $node = new GoogleCalendarNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_calendar',
        nodeName: 'Google Calendar',
        config: [
            'operation' => 'list_events',
            'max_results' => 5,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['event_count'])->toBe(1)
        ->and($result['events'])->toHaveCount(1);
});

it('creates an event', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'id' => 'new_event_1',
            'htmlLink' => 'https://calendar.google.com/test',
            'status' => 'confirmed',
        ], 200),
    ]);

    $node = new GoogleCalendarNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_calendar',
        nodeName: 'Google Calendar',
        config: [
            'operation' => 'create_event',
            'summary' => 'Lunch',
            'start_time' => '2026-03-20T12:00:00Z',
            'end_time' => '2026-03-20T13:00:00Z',
            'attendees' => ['jane@example.com'],
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'event_id' => 'new_event_1',
        'html_link' => 'https://calendar.google.com/test',
        'status' => 'confirmed',
    ]);
});

it('updates an event', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events/event_1*' => Http::response([
            'id' => 'event_1',
            'htmlLink' => 'https://calendar.google.com/test',
            'status' => 'confirmed',
        ], 200),
    ]);

    $node = new GoogleCalendarNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_calendar',
        nodeName: 'Google Calendar',
        config: [
            'operation' => 'update_event',
            'event_id' => 'event_1',
            'summary' => 'Updated Lunch',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'event_id' => 'event_1',
        'html_link' => 'https://calendar.google.com/test',
        'status' => 'confirmed',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'PATCH'
            && $request['summary'] === 'Updated Lunch';
    });
});

it('deletes an event', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events/event_1*' => Http::response([], 204),
    ]);

    $node = new GoogleCalendarNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_calendar',
        nodeName: 'Google Calendar',
        config: [
            'operation' => 'delete_event',
            'event_id' => 'event_1',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'deleted' => true,
        'event_id' => 'event_1',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'DELETE';
    });
});

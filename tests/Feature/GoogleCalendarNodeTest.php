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

it('gets a single event by id', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events/event_1*' => Http::response([
            'id' => 'event_1',
            'summary' => 'Team Meeting',
            'description' => 'Weekly sync',
            'start' => ['dateTime' => '2026-03-20T10:00:00Z'],
            'end' => ['dateTime' => '2026-03-20T11:00:00Z'],
            'htmlLink' => 'https://calendar.google.com/event/event_1',
            'status' => 'confirmed',
            'attendees' => [['email' => 'jane@example.com']],
            'creator' => ['email' => 'john@example.com'],
        ], 200),
    ]);

    $node = new GoogleCalendarNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_calendar',
        nodeName: 'Google Calendar',
        config: [
            'operation' => 'get_event',
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
        'event_id' => 'event_1',
        'summary' => 'Team Meeting',
        'description' => 'Weekly sync',
        'start' => ['dateTime' => '2026-03-20T10:00:00Z'],
        'end' => ['dateTime' => '2026-03-20T11:00:00Z'],
        'html_link' => 'https://calendar.google.com/event/event_1',
        'status' => 'confirmed',
        'attendees' => [['email' => 'jane@example.com']],
        'creator' => ['email' => 'john@example.com'],
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), '/events/event_1');
    });
});

it('lists all calendars', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                ['id' => 'primary@gmail.com', 'summary' => 'Primary', 'primary' => true],
                ['id' => 'work@group.calendar.google.com', 'summary' => 'Work'],
            ],
        ], 200),
    ]);

    $node = new GoogleCalendarNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_calendar',
        nodeName: 'Google Calendar',
        config: [
            'operation' => 'list_calendars',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['calendar_count'])->toBe(2)
        ->and($result['calendars'])->toHaveCount(2)
        ->and($result['calendars'][0])->toBe([
            'id' => 'primary@gmail.com',
            'summary' => 'Primary',
            'primary' => true,
        ])
        ->and($result['calendars'][1]['primary'])->toBeFalse();
});

it('lists events with time range and query', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [
                ['id' => 'event_1', 'summary' => 'Standup'],
            ],
            'nextPageToken' => 'token_abc',
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
            'time_min' => '2026-03-01T00:00:00Z',
            'time_max' => '2026-03-31T23:59:59Z',
            'query' => 'standup',
            'show_deleted' => false,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['event_count'])->toBe(1)
        ->and($result['events'])->toHaveCount(1)
        ->and($result['next_page_token'])->toBe('token_abc');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'timeMin=2026-03-01T00:00:00Z')
            && str_contains($url, 'timeMax=2026-03-31T23:59:59Z')
            && str_contains($url, 'q=standup');
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

<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

/**
 * Handles Google Calendar operations: list_events, create_event, update_event, delete_event.
 */
class GoogleCalendarNode extends AppNode
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3';

    protected function errorCode(): string
    {
        return 'GOOGLE_CALENDAR_ERROR';
    }

    protected function operations(): array
    {
        return [
            'list_events' => $this->listEvents(...),
            'create_event' => $this->createEvent(...),
            'update_event' => $this->updateEvent(...),
            'delete_event' => $this->deleteEvent(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listEvents(NodePayload $payload): array
    {
        $config = $payload->config;
        $calendarId = $config['calendar_id'] ?? 'primary';
        $maxResults = $config['max_results'] ?? 10;

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL."/calendars/{$calendarId}/events", [
                'maxResults' => $maxResults,
                'timeMin' => now()->toIso8601String(),
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ]);

        $response->throw();

        return [
            'events' => $response->json('items', []),
            'event_count' => count($response->json('items', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createEvent(NodePayload $payload): array
    {
        $config = $payload->config;
        $calendarId = $config['calendar_id'] ?? 'primary';

        $event = [
            'summary' => $payload->inputData['summary'] ?? $config['summary'],
            'description' => $payload->inputData['description'] ?? $config['description'] ?? '',
            'start' => [
                'dateTime' => $payload->inputData['start_time'] ?? $config['start_time'],
                'timeZone' => $config['timezone'] ?? 'UTC',
            ],
            'end' => [
                'dateTime' => $payload->inputData['end_time'] ?? $config['end_time'],
                'timeZone' => $config['timezone'] ?? 'UTC',
            ],
        ];

        if ($attendees = $payload->inputData['attendees'] ?? $config['attendees'] ?? null) {
            $event['attendees'] = array_map(fn (string $email) => ['email' => $email], (array) $attendees);
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/calendars/{$calendarId}/events", $event);

        $response->throw();

        return [
            'event_id' => $response->json('id'),
            'html_link' => $response->json('htmlLink'),
            'status' => $response->json('status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateEvent(NodePayload $payload): array
    {
        $config = $payload->config;
        $calendarId = $config['calendar_id'] ?? 'primary';
        $eventId = $payload->inputData['event_id'] ?? $config['event_id'];

        $updates = array_filter([
            'summary' => $payload->inputData['summary'] ?? $config['summary'] ?? null,
            'description' => $payload->inputData['description'] ?? $config['description'] ?? null,
        ]);

        if ($startTime = $payload->inputData['start_time'] ?? $config['start_time'] ?? null) {
            $updates['start'] = ['dateTime' => $startTime, 'timeZone' => $config['timezone'] ?? 'UTC'];
        }

        if ($endTime = $payload->inputData['end_time'] ?? $config['end_time'] ?? null) {
            $updates['end'] = ['dateTime' => $endTime, 'timeZone' => $config['timezone'] ?? 'UTC'];
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->patch(self::BASE_URL."/calendars/{$calendarId}/events/{$eventId}", $updates);

        $response->throw();

        return [
            'event_id' => $response->json('id'),
            'html_link' => $response->json('htmlLink'),
            'status' => $response->json('status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteEvent(NodePayload $payload): array
    {
        $config = $payload->config;
        $calendarId = $config['calendar_id'] ?? 'primary';
        $eventId = $payload->inputData['event_id'] ?? $config['event_id'];

        $response = $this->authenticatedRequest($payload->credentials)
            ->delete(self::BASE_URL."/calendars/{$calendarId}/events/{$eventId}");

        $response->throw();

        return [
            'deleted' => true,
            'event_id' => $eventId,
        ];
    }
}

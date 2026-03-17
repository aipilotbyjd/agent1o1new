<?php

namespace App\Engine\Nodes\Apps\Notion;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

class NotionNode extends AppNode
{
    private const BASE_URL = 'https://api.notion.com/v1';

    protected function errorCode(): string
    {
        return 'NOTION_ERROR';
    }

    protected function operations(): array
    {
        return [
            'create_page' => $this->createPage(...),
            'query_database' => $this->queryDatabase(...),
            'update_page' => $this->updatePage(...),
        ];
    }

    private function getHeaders(): array
    {
        return [
            'Notion-Version' => '2022-06-28',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPage(NodePayload $payload): array
    {
        $config = $payload->config;
        $databaseId = $config['database_id'] ?? '';
        $properties = $payload->inputData['properties'] ?? $config['properties'] ?? [];
        $children = $payload->inputData['children'] ?? [];

        $data = [
            'parent' => ['database_id' => $databaseId],
            'properties' => $properties,
        ];

        if (! empty($children)) {
            $data['children'] = $children;
        }

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->post(self::BASE_URL.'/pages', $data);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function queryDatabase(NodePayload $payload): array
    {
        $config = $payload->config;
        $databaseId = $config['database_id'] ?? '';
        $filter = $payload->inputData['filter'] ?? $config['filter'] ?? [];

        $data = [];
        if (! empty($filter)) {
            $data['filter'] = $filter;
        }

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->post(self::BASE_URL."/databases/{$databaseId}/query", $data);

        $response->throw();

        return [
            'results' => $response->json('results', []),
            'has_more' => $response->json('has_more', false),
            'next_cursor' => $response->json('next_cursor'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePage(NodePayload $payload): array
    {
        $config = $payload->config;
        $pageId = $config['page_id'] ?? '';
        $properties = $payload->inputData['properties'] ?? $config['properties'] ?? [];

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->patch(self::BASE_URL."/pages/{$pageId}", [
                'properties' => $properties,
            ]);

        $response->throw();

        return $response->json();
    }
}

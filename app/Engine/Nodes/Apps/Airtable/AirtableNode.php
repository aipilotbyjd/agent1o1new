<?php

namespace App\Engine\Nodes\Apps\Airtable;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

class AirtableNode extends AppNode
{
    private const BASE_URL = 'https://api.airtable.com/v0';

    protected function errorCode(): string
    {
        return 'AIRTABLE_ERROR';
    }

    protected function operations(): array
    {
        return [
            'list_records' => $this->listRecords(...),
            'get_record' => $this->getRecord(...),
            'create_record' => $this->createRecord(...),
            'update_record' => $this->updateRecord(...),
            'delete_record' => $this->deleteRecord(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRecords(NodePayload $payload): array
    {
        $config = $payload->config;
        $baseId = $config['base_id'];
        $tableName = $config['table_name'];

        $params = [
            'maxRecords' => (int) ($config['max_records'] ?? 100),
        ];

        if (! empty($config['view'])) {
            $params['view'] = $config['view'];
        }

        if (! empty($config['filter_by_formula'])) {
            $params['filterByFormula'] = $config['filter_by_formula'];
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL."/{$baseId}/{$tableName}", $params);

        $response->throw();

        return [
            'records' => $response->json('records', []),
            'offset' => $response->json('offset'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRecord(NodePayload $payload): array
    {
        $config = $payload->config;
        $baseId = $config['base_id'];
        $tableName = $config['table_name'];
        $recordId = $config['record_id'];

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL."/{$baseId}/{$tableName}/{$recordId}");

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function createRecord(NodePayload $payload): array
    {
        $config = $payload->config;
        $baseId = $config['base_id'];
        $tableName = $config['table_name'];
        $fields = $payload->inputData['fields'] ?? $config['fields'] ?? [];

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/{$baseId}/{$tableName}", [
                'fields' => $fields,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function updateRecord(NodePayload $payload): array
    {
        $config = $payload->config;
        $baseId = $config['base_id'];
        $tableName = $config['table_name'];
        $recordId = $config['record_id'];
        $fields = $payload->inputData['fields'] ?? $config['fields'] ?? [];

        $response = $this->authenticatedRequest($payload->credentials)
            ->patch(self::BASE_URL."/{$baseId}/{$tableName}/{$recordId}", [
                'fields' => $fields,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteRecord(NodePayload $payload): array
    {
        $config = $payload->config;
        $baseId = $config['base_id'];
        $tableName = $config['table_name'];
        $recordId = $config['record_id'];

        $response = $this->authenticatedRequest($payload->credentials)
            ->delete(self::BASE_URL."/{$baseId}/{$tableName}/{$recordId}");

        $response->throw();

        return $response->json();
    }
}

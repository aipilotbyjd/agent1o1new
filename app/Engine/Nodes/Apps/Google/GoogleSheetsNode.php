<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

/**
 * Handles all Google Sheets operations: get_rows, append_row, update_row.
 */
class GoogleSheetsNode extends AppNode
{
    private const BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets';

    protected function errorCode(): string
    {
        return 'GOOGLE_SHEETS_ERROR';
    }

    protected function operations(): array
    {
        return [
            'get_rows' => $this->getRows(...),
            'append_row' => $this->appendRow(...),
            'update_row' => $this->updateRow(...),
            'clear_range' => $this->clearRange(...),
            'delete_rows' => $this->deleteRows(...),
            'lookup_rows' => $this->lookupRows(...),
            'create_spreadsheet' => $this->createSpreadsheet(...),
            'get_spreadsheet_info' => $this->getSpreadsheetInfo(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRows(NodePayload $payload): array
    {
        $config = $payload->config;
        $spreadsheetId = $config['spreadsheet_id'];
        $range = $config['range'] ?? 'Sheet1';

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL."/{$spreadsheetId}/values/{$range}");

        $response->throw();

        $values = $response->json('values', []);
        $headers = array_shift($values) ?? [];

        $rows = array_map(
            fn (array $row) => array_combine(
                $headers,
                array_pad($row, count($headers), null),
            ),
            $values,
        );

        return [
            'rows' => $rows,
            'row_count' => count($rows),
            'headers' => $headers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appendRow(NodePayload $payload): array
    {
        $config = $payload->config;
        $spreadsheetId = $config['spreadsheet_id'];
        $range = $config['range'] ?? 'Sheet1';
        $values = $payload->inputData['values'] ?? $config['values'] ?? [];

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->withQueryParameters([
                'valueInputOption' => 'USER_ENTERED',
                'insertDataOption' => 'INSERT_ROWS',
            ])
            ->post(self::BASE_URL."/{$spreadsheetId}/values/{$range}:append", [
                'values' => $values,
            ]);

        $response->throw();

        return [
            'updated_range' => $response->json('updates.updatedRange'),
            'updated_rows' => $response->json('updates.updatedRows', 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateRow(NodePayload $payload): array
    {
        $config = $payload->config;
        $spreadsheetId = $config['spreadsheet_id'];
        $range = $config['range'] ?? 'Sheet1!A1';
        $values = $payload->inputData['values'] ?? $config['values'] ?? [];

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->withQueryParameters([
                'valueInputOption' => 'USER_ENTERED',
            ])
            ->put(self::BASE_URL."/{$spreadsheetId}/values/{$range}", [
                'values' => $values,
            ]);

        $response->throw();

        return [
            'updated_range' => $response->json('updatedRange'),
            'updated_rows' => $response->json('updatedRows', 0),
            'updated_cells' => $response->json('updatedCells', 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clearRange(NodePayload $payload): array
    {
        $config = $payload->config;
        $spreadsheetId = $config['spreadsheet_id'];
        $range = $config['range'] ?? 'Sheet1';

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/{$spreadsheetId}/values/{$range}:clear");

        $response->throw();

        return [
            'cleared_range' => $response->json('clearedRange'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteRows(NodePayload $payload): array
    {
        $config = $payload->config;
        $spreadsheetId = $config['spreadsheet_id'];
        $sheetId = (int) ($config['sheet_id'] ?? 0);
        $startIndex = (int) ($config['start_index'] ?? 0);
        $endIndex = (int) ($config['end_index'] ?? $startIndex + 1);

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/{$spreadsheetId}:batchUpdate", [
                'requests' => [
                    [
                        'deleteDimension' => [
                            'range' => [
                                'sheetId' => $sheetId,
                                'dimension' => 'ROWS',
                                'startIndex' => $startIndex,
                                'endIndex' => $endIndex,
                            ],
                        ],
                    ],
                ],
            ]);

        $response->throw();

        return [
            'deleted_rows' => $endIndex - $startIndex,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupRows(NodePayload $payload): array
    {
        $result = $this->getRows($payload);

        $filterColumn = $payload->config['filter_column'] ?? '';
        $filterValue = $payload->config['filter_value'] ?? '';

        $matchedRows = array_values(array_filter(
            $result['rows'],
            fn (array $row) => ($row[$filterColumn] ?? null) === $filterValue,
        ));

        return [
            'rows' => $matchedRows,
            'row_count' => count($matchedRows),
            'headers' => $result['headers'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createSpreadsheet(NodePayload $payload): array
    {
        $title = $payload->config['title'] ?? 'Untitled Spreadsheet';

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL, [
                'properties' => [
                    'title' => $title,
                ],
            ]);

        $response->throw();

        return [
            'spreadsheet_id' => $response->json('spreadsheetId'),
            'spreadsheet_url' => $response->json('spreadsheetUrl'),
            'title' => $response->json('properties.title'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSpreadsheetInfo(NodePayload $payload): array
    {
        $spreadsheetId = $payload->config['spreadsheet_id'];

        $response = $this->authenticatedRequest($payload->credentials)
            ->withQueryParameters([
                'fields' => 'properties,sheets.properties',
            ])
            ->get(self::BASE_URL."/{$spreadsheetId}");

        $response->throw();

        return [
            'properties' => $response->json('properties', []),
            'sheets' => $response->json('sheets', []),
        ];
    }
}

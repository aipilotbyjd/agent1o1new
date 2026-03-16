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
}

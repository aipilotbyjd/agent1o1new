<?php

use App\Engine\Nodes\Apps\Google\GoogleSheetsNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('fetches rows from google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1' => Http::response([
            'values' => [
                ['id', 'name'],
                ['1', 'John'],
                ['2', 'Doe'],
            ],
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'get_rows',
            'spreadsheet_id' => 'my_sheet_id',
            'range' => 'Sheet1',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'rows' => [
            ['id' => '1', 'name' => 'John'],
            ['id' => '2', 'name' => 'Doe'],
        ],
        'row_count' => 2,
        'headers' => ['id', 'name'],
    ]);
});

it('appends a row to google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1:append*' => Http::response([
            'updates' => [
                'updatedRange' => 'Sheet1!A3:B3',
                'updatedRows' => 1,
            ],
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'append_row',
            'spreadsheet_id' => 'my_sheet_id',
            'range' => 'Sheet1',
            'values' => ['3', 'Jane'],
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['updated_range'])->toBe('Sheet1!A3:B3')
        ->and($result['updated_rows'])->toBe(1);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1:append')
            && str_contains($request->url(), 'valueInputOption=USER_ENTERED')
            && str_contains($request->url(), 'insertDataOption=INSERT_ROWS')
            && $request['values'] === [['3', 'Jane']];
    });
});

it('updates a row in google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1!A2*' => Http::response([
            'updatedRange' => 'Sheet1!A2:B2',
            'updatedRows' => 1,
            'updatedCells' => 2,
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'update_row',
            'spreadsheet_id' => 'my_sheet_id',
            'range' => 'Sheet1!A2',
            'values' => [['1', 'Jane Updated']],
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'updated_range' => 'Sheet1!A2:B2',
        'updated_rows' => 1,
        'updated_cells' => 2,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1!A2')
            && str_contains($request->url(), 'valueInputOption=USER_ENTERED')
            && $request->method() === 'PUT'
            && $request['values'] === [['1', 'Jane Updated']];
    });
});

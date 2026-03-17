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

it('clears a range in google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1!A1:B5:clear' => Http::response([
            'clearedRange' => 'Sheet1!A1:B5',
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'clear_range',
            'spreadsheet_id' => 'my_sheet_id',
            'range' => 'Sheet1!A1:B5',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'cleared_range' => 'Sheet1!A1:B5',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1!A1:B5:clear')
            && $request->method() === 'POST';
    });
});

it('deletes rows in google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id:batchUpdate' => Http::response([
            'replies' => [[]],
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'delete_rows',
            'spreadsheet_id' => 'my_sheet_id',
            'sheet_id' => 0,
            'start_index' => 1,
            'end_index' => 3,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'deleted_rows' => 2,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets/my_sheet_id:batchUpdate')
            && $request->method() === 'POST'
            && $request['requests'][0]['deleteDimension']['range']['startIndex'] === 1
            && $request['requests'][0]['deleteDimension']['range']['endIndex'] === 3;
    });
});

it('looks up rows by column value in google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id/values/Sheet1' => Http::response([
            'values' => [
                ['id', 'name'],
                ['1', 'John'],
                ['2', 'Doe'],
                ['3', 'John'],
            ],
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'lookup_rows',
            'spreadsheet_id' => 'my_sheet_id',
            'range' => 'Sheet1',
            'filter_column' => 'name',
            'filter_value' => 'John',
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
            ['id' => '3', 'name' => 'John'],
        ],
        'row_count' => 2,
        'headers' => ['id', 'name'],
    ]);
});

it('creates a new spreadsheet in google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets' => Http::response([
            'spreadsheetId' => 'new_sheet_id',
            'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/new_sheet_id/edit',
            'properties' => [
                'title' => 'My New Sheet',
            ],
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'create_spreadsheet',
            'title' => 'My New Sheet',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'spreadsheet_id' => 'new_sheet_id',
        'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/new_sheet_id/edit',
        'title' => 'My New Sheet',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets')
            && $request->method() === 'POST'
            && $request['properties']['title'] === 'My New Sheet';
    });
});

it('gets spreadsheet info from google sheets', function () {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/my_sheet_id*' => Http::response([
            'properties' => [
                'title' => 'Test Sheet',
                'locale' => 'en_US',
            ],
            'sheets' => [
                ['properties' => ['sheetId' => 0, 'title' => 'Sheet1']],
                ['properties' => ['sheetId' => 1, 'title' => 'Sheet2']],
            ],
        ], 200),
    ]);

    $node = new GoogleSheetsNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_sheets',
        nodeName: 'Google Sheets',
        config: [
            'operation' => 'get_spreadsheet_info',
            'spreadsheet_id' => 'my_sheet_id',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'properties' => [
            'title' => 'Test Sheet',
            'locale' => 'en_US',
        ],
        'sheets' => [
            ['properties' => ['sheetId' => 0, 'title' => 'Sheet1']],
            ['properties' => ['sheetId' => 1, 'title' => 'Sheet2']],
        ],
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets/my_sheet_id')
            && str_contains($request->url(), 'fields=properties%2Csheets.properties')
            && $request->method() === 'GET';
    });
});

<?php

use App\Engine\Nodes\Apps\Airtable\AirtableNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('lists records from airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appXYZ/Tasks*' => Http::response([
            'records' => [
                ['id' => 'rec1', 'fields' => ['Name' => 'Task 1']],
                ['id' => 'rec2', 'fields' => ['Name' => 'Task 2']],
            ],
            'offset' => 'itr123',
        ], 200),
    ]);

    $node = new AirtableNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'airtable',
        nodeName: 'Airtable',
        config: [
            'operation' => 'list_records',
            'base_id' => 'appXYZ',
            'table_name' => 'Tasks',
            'max_records' => 50,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'fake_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['records'])->toHaveCount(2)
        ->and($result['offset'])->toBe('itr123');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'api.airtable.com/v0/appXYZ/Tasks')
            && str_contains($request->url(), 'maxRecords=50')
            && $request->method() === 'GET';
    });
});

it('gets a single record from airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appXYZ/Tasks/rec1' => Http::response([
            'id' => 'rec1',
            'fields' => ['Name' => 'Task 1', 'Status' => 'Done'],
        ], 200),
    ]);

    $node = new AirtableNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'airtable',
        nodeName: 'Airtable',
        config: [
            'operation' => 'get_record',
            'base_id' => 'appXYZ',
            'table_name' => 'Tasks',
            'record_id' => 'rec1',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'fake_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'id' => 'rec1',
        'fields' => ['Name' => 'Task 1', 'Status' => 'Done'],
    ]);
});

it('creates a record in airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appXYZ/Tasks' => Http::response([
            'id' => 'rec_new',
            'fields' => ['Name' => 'New Task', 'Status' => 'Todo'],
        ], 200),
    ]);

    $node = new AirtableNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'airtable',
        nodeName: 'Airtable',
        config: [
            'operation' => 'create_record',
            'base_id' => 'appXYZ',
            'table_name' => 'Tasks',
        ],
        inputData: [
            'fields' => ['Name' => 'New Task', 'Status' => 'Todo'],
        ],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'fake_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['id'])->toBe('rec_new')
        ->and($result['fields']['Name'])->toBe('New Task');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'api.airtable.com/v0/appXYZ/Tasks')
            && $request->method() === 'POST'
            && $request['fields']['Name'] === 'New Task';
    });
});

it('updates a record in airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appXYZ/Tasks/rec1' => Http::response([
            'id' => 'rec1',
            'fields' => ['Name' => 'Updated Task', 'Status' => 'Done'],
        ], 200),
    ]);

    $node = new AirtableNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'airtable',
        nodeName: 'Airtable',
        config: [
            'operation' => 'update_record',
            'base_id' => 'appXYZ',
            'table_name' => 'Tasks',
            'record_id' => 'rec1',
        ],
        inputData: [
            'fields' => ['Name' => 'Updated Task', 'Status' => 'Done'],
        ],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'fake_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['id'])->toBe('rec1')
        ->and($result['fields']['Name'])->toBe('Updated Task');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'api.airtable.com/v0/appXYZ/Tasks/rec1')
            && $request->method() === 'PATCH'
            && $request['fields']['Name'] === 'Updated Task';
    });
});

it('deletes a record in airtable', function () {
    Http::fake([
        'api.airtable.com/v0/appXYZ/Tasks/rec1' => Http::response([
            'id' => 'rec1',
            'deleted' => true,
        ], 200),
    ]);

    $node = new AirtableNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'airtable',
        nodeName: 'Airtable',
        config: [
            'operation' => 'delete_record',
            'base_id' => 'appXYZ',
            'table_name' => 'Tasks',
            'record_id' => 'rec1',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'fake_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'id' => 'rec1',
        'deleted' => true,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'api.airtable.com/v0/appXYZ/Tasks/rec1')
            && $request->method() === 'DELETE';
    });
});

it('returns error for unknown airtable operation', function () {
    $node = new AirtableNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'airtable',
        nodeName: 'Airtable',
        config: [
            'operation' => 'invalid_op',
            'base_id' => 'appXYZ',
            'table_name' => 'Tasks',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'fake_token',
        ]
    );

    $result = $node->handle($payload);

    expect($result->error['code'])->toBe('AIRTABLE_ERROR');
});

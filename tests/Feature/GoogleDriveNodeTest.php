<?php

use App\Engine\Nodes\Apps\Google\GoogleDriveNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('lists files from google drive', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files*' => Http::response([
            'files' => [
                ['id' => 'file_1', 'name' => 'Report.pdf'],
                ['id' => 'file_2', 'name' => 'Invoice.pdf'],
            ],
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'list_files',
            'folder_id' => 'folder_1',
            'page_size' => 10,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['file_count'])->toBe(2)
        ->and($result['files'])->toHaveCount(2);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, "q='folder_1' in parents and trashed = false")
            && str_contains($url, 'pageSize=10');
    });
});

it('creates a folder in google drive', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files*' => Http::response([
            'id' => 'new_folder_id',
            'name' => 'My Folder',
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'create_folder',
            'name' => 'My Folder',
            'parent_id' => 'root_folder_id',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'folder_id' => 'new_folder_id',
        'name' => 'My Folder',
    ]);
});

it('uploads a file to google drive', function () {
    Http::fake([
        'www.googleapis.com/upload/drive/v3/files*' => Http::response([
            'id' => 'new_file_id',
            'name' => 'document.txt',
            'mimeType' => 'text/plain',
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'upload_file',
            'name' => 'document.txt',
            'content' => 'Hello World',
            'mime_type' => 'text/plain',
            'parent_id' => 'folder_xyz',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'file_id' => 'new_file_id',
        'name' => 'document.txt',
        'mime_type' => 'text/plain',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $body = $request->body();
        $headers = $request->headers();
        $contentType = $headers['Content-Type'][0] ?? '';

        return str_contains($contentType, 'multipart/related')
            && str_contains($body, 'document.txt')
            && str_contains($body, 'Hello World')
            && str_contains($body, 'folder_xyz');
    });
});

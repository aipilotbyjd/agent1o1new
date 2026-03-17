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

        return str_contains($url, "q=trashed = false and 'folder_1' in parents")
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

it('lists files with search query and pagination', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files*' => Http::response([
            'files' => [
                ['id' => 'file_1', 'name' => 'Report.pdf'],
            ],
            'nextPageToken' => 'next_abc',
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'list_files',
            'name_contains' => 'Report',
            'order_by' => 'modifiedTime desc',
            'page_size' => 5,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result['file_count'])->toBe(1)
        ->and($result['next_page_token'])->toBe('next_abc');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, "name contains 'Report'")
            && str_contains($url, 'orderBy=modifiedTime desc');
    });
});

it('downloads a file from google drive', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files/file_1*' => Http::response('File content here', 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'download_file',
            'file_id' => 'file_1',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'content' => 'File content here',
        'file_id' => 'file_1',
        'content_length' => 17,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, '/files/file_1')
            && str_contains($url, 'alt=media');
    });
});

it('downloads a google doc using export', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files/doc_1/export*' => Http::response('Exported content', 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'download_file',
            'file_id' => 'doc_1',
            'export_mime_type' => 'application/pdf',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'content' => 'Exported content',
        'file_id' => 'doc_1',
        'content_length' => 16,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, '/files/doc_1/export')
            && str_contains($url, 'mimeType=application/pdf');
    });
});

it('updates file metadata only', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files/file_1*' => Http::response([
            'id' => 'file_1',
            'name' => 'Renamed.pdf',
            'mimeType' => 'application/pdf',
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'update_file',
            'file_id' => 'file_1',
            'name' => 'Renamed.pdf',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'file_id' => 'file_1',
        'name' => 'Renamed.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'PATCH'
            && $request['name'] === 'Renamed.pdf';
    });
});

it('updates file with content using multipart upload', function () {
    Http::fake([
        'www.googleapis.com/upload/drive/v3/files/file_1*' => Http::response([
            'id' => 'file_1',
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
            'operation' => 'update_file',
            'file_id' => 'file_1',
            'name' => 'document.txt',
            'content' => 'Updated content',
            'mime_type' => 'text/plain',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'file_id' => 'file_1',
        'name' => 'document.txt',
        'mime_type' => 'text/plain',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $body = $request->body();
        $headers = $request->headers();
        $contentType = $headers['Content-Type'][0] ?? '';

        return $request->method() === 'PATCH'
            && str_contains($contentType, 'multipart/related')
            && str_contains($body, 'Updated content')
            && str_contains($request->url(), 'uploadType=multipart');
    });
});

it('soft deletes a file by trashing', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files/file_1*' => Http::response([
            'id' => 'file_1',
            'trashed' => true,
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'delete_file',
            'file_id' => 'file_1',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'file_id' => 'file_1',
        'deleted' => true,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'PATCH'
            && $request['trashed'] === true;
    });
});

it('permanently deletes a file', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files/file_1*' => Http::response([], 204),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'delete_file',
            'file_id' => 'file_1',
            'permanent' => true,
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'file_id' => 'file_1',
        'deleted' => true,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'DELETE';
    });
});

it('shares a file with a user', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/files/file_1/permissions*' => Http::response([
            'id' => 'perm_1',
            'role' => 'writer',
            'type' => 'user',
        ], 200),
    ]);

    $node = new GoogleDriveNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'google_drive',
        nodeName: 'Google Drive',
        config: [
            'operation' => 'share_file',
            'file_id' => 'file_1',
            'role' => 'writer',
            'type' => 'user',
            'email_address' => 'jane@example.com',
        ],
        inputData: [],
        credentials: [
            'token_type' => 'Bearer',
            'access_token' => 'foo_token',
        ]
    );

    $result = $node->handle($payload)->output;

    expect($result)->toBe([
        'permission_id' => 'perm_1',
        'role' => 'writer',
        'type' => 'user',
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/permissions')
            && $request['role'] === 'writer'
            && $request['emailAddress'] === 'jane@example.com';
    });
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

<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

/**
 * Handles Google Drive operations: list_files, create_folder, upload_file.
 */
class GoogleDriveNode extends AppNode
{
    private const BASE_URL = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3';

    protected function errorCode(): string
    {
        return 'GOOGLE_DRIVE_ERROR';
    }

    protected function operations(): array
    {
        return [
            'list_files' => $this->listFiles(...),
            'create_folder' => $this->createFolder(...),
            'upload_file' => $this->uploadFile(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listFiles(NodePayload $payload): array
    {
        $config = $payload->config;
        $folderId = $payload->inputData['folder_id'] ?? $config['folder_id'] ?? null;
        $pageSize = $config['page_size'] ?? 20;

        $query = $folderId ? "'{$folderId}' in parents and trashed = false" : 'trashed = false';

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL.'/files', [
                'q' => $query,
                'pageSize' => $pageSize,
                'fields' => 'files(id,name,mimeType,size,createdTime,modifiedTime)',
            ]);

        $response->throw();

        return [
            'files' => $response->json('files', []),
            'file_count' => count($response->json('files', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createFolder(NodePayload $payload): array
    {
        $config = $payload->config;
        $name = $payload->inputData['name'] ?? $config['name'];
        $parentId = $payload->inputData['parent_id'] ?? $config['parent_id'] ?? null;

        $metadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];

        if ($parentId) {
            $metadata['parents'] = [$parentId];
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL.'/files', $metadata);

        $response->throw();

        return [
            'folder_id' => $response->json('id'),
            'name' => $response->json('name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadFile(NodePayload $payload): array
    {
        $config = $payload->config;
        $name = $payload->inputData['name'] ?? $config['name'];
        $content = $payload->inputData['content'] ?? $config['content'] ?? '';
        $mimeType = $payload->inputData['mime_type'] ?? $config['mime_type'] ?? 'text/plain';
        $parentId = $payload->inputData['parent_id'] ?? $config['parent_id'] ?? null;

        $metadata = ['name' => $name];
        if ($parentId) {
            $metadata['parents'] = [$parentId];
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(self::UPLOAD_URL.'/files?uploadType=multipart', [
                'metadata' => $metadata,
                'data' => base64_encode($content),
                'mimeType' => $mimeType,
            ]);

        $response->throw();

        return [
            'file_id' => $response->json('id'),
            'name' => $response->json('name'),
            'mime_type' => $response->json('mimeType'),
        ];
    }
}

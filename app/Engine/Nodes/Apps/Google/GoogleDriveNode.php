<?php

namespace App\Engine\Nodes\Apps\Google;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

/**
 * Handles Google Drive operations: list_files, download_file, upload_file, update_file, create_folder, delete_file, share_file.
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
            'download_file' => $this->downloadFile(...),
            'upload_file' => $this->uploadFile(...),
            'update_file' => $this->updateFile(...),
            'create_folder' => $this->createFolder(...),
            'delete_file' => $this->deleteFile(...),
            'share_file' => $this->shareFile(...),
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

        $queryParts = ['trashed = false'];

        if ($folderId) {
            $queryParts[] = "'{$folderId}' in parents";
        }

        if ($nameContains = $config['name_contains'] ?? null) {
            $queryParts[] = "name contains '{$nameContains}'";
        }

        $query = $config['query'] ?? implode(' and ', $queryParts);

        $params = [
            'q' => $query,
            'pageSize' => $pageSize,
            'fields' => 'files(id,name,mimeType,size,createdTime,modifiedTime),nextPageToken',
        ];

        if ($pageToken = $config['page_token'] ?? null) {
            $params['pageToken'] = $pageToken;
        }

        if ($orderBy = $config['order_by'] ?? null) {
            $params['orderBy'] = $orderBy;
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->get(self::BASE_URL.'/files', $params);

        $response->throw();

        return [
            'files' => $response->json('files', []),
            'file_count' => count($response->json('files', [])),
            'next_page_token' => $response->json('nextPageToken'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadFile(NodePayload $payload): array
    {
        $config = $payload->config;
        $fileId = $payload->inputData['file_id'] ?? $config['file_id'];
        $exportMimeType = $config['export_mime_type'] ?? null;

        if ($exportMimeType) {
            $response = $this->authenticatedRequest($payload->credentials)
                ->get(self::BASE_URL."/files/{$fileId}/export", [
                    'mimeType' => $exportMimeType,
                ]);
        } else {
            $response = $this->authenticatedRequest($payload->credentials)
                ->get(self::BASE_URL."/files/{$fileId}", [
                    'alt' => 'media',
                ]);
        }

        $response->throw();

        return [
            'content' => $response->body(),
            'file_id' => $fileId,
            'content_length' => strlen($response->body()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateFile(NodePayload $payload): array
    {
        $config = $payload->config;
        $fileId = $payload->inputData['file_id'] ?? $config['file_id'];
        $content = $payload->inputData['content'] ?? $config['content'] ?? null;

        $metadata = array_filter([
            'name' => $payload->inputData['name'] ?? $config['name'] ?? null,
            'mimeType' => $payload->inputData['mime_type'] ?? $config['mime_type'] ?? null,
        ]);

        if ($content !== null) {
            $mimeType = $metadata['mimeType'] ?? 'text/plain';
            $boundary = 'boundary_'.bin2hex(random_bytes(8));

            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
            $body .= json_encode($metadata)."\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$mimeType}\r\n\r\n";
            $body .= $content."\r\n";
            $body .= "--{$boundary}--";

            $response = $this->authenticatedRequest($payload->credentials)
                ->withBody($body, "multipart/related; boundary={$boundary}")
                ->patch(self::UPLOAD_URL."/files/{$fileId}?uploadType=multipart");
        } else {
            $response = $this->authenticatedRequest($payload->credentials)
                ->patch(self::BASE_URL."/files/{$fileId}", $metadata);
        }

        $response->throw();

        return [
            'file_id' => $response->json('id'),
            'name' => $response->json('name'),
            'mime_type' => $response->json('mimeType'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteFile(NodePayload $payload): array
    {
        $config = $payload->config;
        $fileId = $payload->inputData['file_id'] ?? $config['file_id'];
        $permanent = $config['permanent'] ?? false;

        if ($permanent) {
            $response = $this->authenticatedRequest($payload->credentials)
                ->delete(self::BASE_URL."/files/{$fileId}");
        } else {
            $response = $this->authenticatedRequest($payload->credentials)
                ->patch(self::BASE_URL."/files/{$fileId}", ['trashed' => true]);
        }

        $response->throw();

        return [
            'file_id' => $fileId,
            'deleted' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shareFile(NodePayload $payload): array
    {
        $config = $payload->config;
        $fileId = $payload->inputData['file_id'] ?? $config['file_id'];

        $permission = [
            'role' => $config['role'],
            'type' => $config['type'],
        ];

        if ($emailAddress = $config['email_address'] ?? null) {
            $permission['emailAddress'] = $emailAddress;
        }

        $response = $this->authenticatedRequest($payload->credentials)
            ->post(self::BASE_URL."/files/{$fileId}/permissions", $permission);

        $response->throw();

        return [
            'permission_id' => $response->json('id'),
            'role' => $response->json('role'),
            'type' => $response->json('type'),
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

        $boundary = 'boundary_'.bin2hex(random_bytes(8));

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata)."\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $content."\r\n";
        $body .= "--{$boundary}--";

        $response = $this->authenticatedRequest($payload->credentials)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(self::UPLOAD_URL.'/files?uploadType=multipart');

        $response->throw();

        return [
            'file_id' => $response->json('id'),
            'name' => $response->json('name'),
            'mime_type' => $response->json('mimeType'),
        ];
    }
}

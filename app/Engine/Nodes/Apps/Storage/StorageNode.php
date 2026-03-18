<?php

namespace App\Engine\Nodes\Apps\Storage;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Storage;

class StorageNode extends AppNode
{
    protected function errorCode(): string
    {
        return 'STORAGE_ERROR';
    }

    protected function operations(): array
    {
        return [
            'read_file' => $this->readFile(...),
            'write_file' => $this->writeFile(...),
        ];
    }

    /**
     * Read a file from disk using Laravel's Storage facade.
     *
     * @return array<string, mixed>
     */
    private function readFile(NodePayload $payload): array
    {
        $disk = $payload->config['disk'] ?? 'local';
        $path = $payload->config['path'] ?? '';
        $encoding = $payload->config['encoding'] ?? 'utf-8';

        $content = Storage::disk($disk)->get($path);

        if ($content === null) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $size = Storage::disk($disk)->size($path);

        $fullPath = Storage::disk($disk)->path($path);
        $mimeType = file_exists($fullPath)
            ? (mime_content_type($fullPath) ?: 'application/octet-stream')
            : 'application/octet-stream';

        if ($encoding === 'base64') {
            $content = base64_encode($content);
        }

        return [
            'content' => $content,
            'size' => $size,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Write a file to disk using Laravel's Storage facade.
     *
     * @return array<string, mixed>
     */
    private function writeFile(NodePayload $payload): array
    {
        $disk = $payload->config['disk'] ?? 'local';
        $path = $payload->config['path'] ?? '';
        $content = $payload->inputData['content'] ?? '';

        Storage::disk($disk)->put($path, $content);

        $size = Storage::disk($disk)->size($path);

        return [
            'path' => $path,
            'size' => $size,
        ];
    }
}

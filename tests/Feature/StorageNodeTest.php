<?php

use App\Engine\Nodes\Apps\Storage\StorageNode;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionNodeStatus;
use Illuminate\Support\Facades\Storage;

function storagePayload(string $operation, array $config = [], array $inputData = []): NodePayload
{
    return new NodePayload(
        nodeId: 'test_node',
        nodeType: 'storage',
        nodeName: 'Storage',
        config: array_merge(['operation' => $operation], $config),
        inputData: $inputData,
    );
}

it('reads a file from disk', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.txt', 'Hello World');

    $node = new StorageNode;

    $result = $node->handle(storagePayload('read_file', [
        'disk' => 'local',
        'path' => 'test.txt',
    ]));

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['content'])->toBe('Hello World')
        ->and($result->output['size'])->toBe(11)
        ->and($result->output['mime_type'])->toBeString();
});

it('reads a file with base64 encoding', function () {
    Storage::fake('local');
    Storage::disk('local')->put('binary.dat', 'binary content');

    $node = new StorageNode;

    $result = $node->handle(storagePayload('read_file', [
        'disk' => 'local',
        'path' => 'binary.dat',
        'encoding' => 'base64',
    ]));

    expect($result->output['content'])->toBe(base64_encode('binary content'));
});

it('fails when reading a non-existent file', function () {
    Storage::fake('local');

    $node = new StorageNode;

    $result = $node->handle(storagePayload('read_file', [
        'disk' => 'local',
        'path' => 'nonexistent.txt',
    ]));

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('STORAGE_ERROR');
});

it('writes a file to disk', function () {
    Storage::fake('local');

    $node = new StorageNode;

    $result = $node->handle(storagePayload('write_file', [
        'disk' => 'local',
        'path' => 'output.txt',
    ], [
        'content' => 'File content here',
    ]));

    expect($result->status)->toBe(ExecutionNodeStatus::Completed)
        ->and($result->output['path'])->toBe('output.txt')
        ->and($result->output['size'])->toBe(17);

    Storage::disk('local')->assertExists('output.txt');
    expect(Storage::disk('local')->get('output.txt'))->toBe('File content here');
});

it('returns failed result for unknown operation', function () {
    $node = new StorageNode;

    $result = $node->handle(storagePayload('unknown_op'));

    expect($result->status)->toBe(ExecutionNodeStatus::Failed)
        ->and($result->error['code'])->toBe('STORAGE_ERROR');
});

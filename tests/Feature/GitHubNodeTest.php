<?php

use App\Engine\Nodes\Apps\GitHub\GitHubNode;
use App\Engine\Runners\NodePayload;
use Illuminate\Support\Facades\Http;

it('lists repos', function () {
    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            ['id' => 1, 'name' => 'repo-one'],
            ['id' => 2, 'name' => 'repo-two'],
        ], 200),
    ]);

    $node = new GitHubNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'github',
        nodeName: 'GitHub',
        config: [
            'operation' => 'list_repos',
            'per_page' => 10,
            'sort' => 'created',
        ],
        inputData: [],
        credentials: ['access_token' => 'ghp_test123'],
    );

    $result = $node->handle($payload);

    expect($result->output['repos'])->toHaveCount(2);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), 'api.github.com/user/repos')
            && str_contains($request->url(), 'per_page=10')
            && $request->hasHeader('Accept', 'application/vnd.github.v3+json')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28');
    });
});

it('creates an issue', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues' => Http::response([
            'id' => 42,
            'number' => 7,
            'title' => 'Bug report',
            'state' => 'open',
        ], 201),
    ]);

    $node = new GitHubNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'github',
        nodeName: 'GitHub',
        config: [
            'operation' => 'create_issue',
            'owner' => 'owner',
            'repo' => 'repo',
        ],
        inputData: [
            'title' => 'Bug report',
            'body' => 'Something is broken',
            'labels' => ['bug'],
        ],
        credentials: ['access_token' => 'ghp_test123'],
    );

    $result = $node->handle($payload);

    expect($result->output)
        ->toHaveKey('id', 42)
        ->toHaveKey('title', 'Bug report');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'repos/owner/repo/issues')
            && $request['title'] === 'Bug report'
            && $request['labels'] === ['bug'];
    });
});

it('lists issues', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues*' => Http::response([
            ['id' => 1, 'title' => 'Issue one'],
            ['id' => 2, 'title' => 'Issue two'],
        ], 200),
    ]);

    $node = new GitHubNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'github',
        nodeName: 'GitHub',
        config: [
            'operation' => 'list_issues',
            'owner' => 'owner',
            'repo' => 'repo',
            'state' => 'all',
            'per_page' => 5,
        ],
        inputData: [],
        credentials: ['access_token' => 'ghp_test123'],
    );

    $result = $node->handle($payload);

    expect($result->output['issues'])->toHaveCount(2);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), 'repos/owner/repo/issues')
            && str_contains($request->url(), 'state=all')
            && str_contains($request->url(), 'per_page=5');
    });
});

it('creates a pull request', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/pulls' => Http::response([
            'id' => 99,
            'number' => 3,
            'title' => 'My PR',
            'state' => 'open',
        ], 201),
    ]);

    $node = new GitHubNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'github',
        nodeName: 'GitHub',
        config: [
            'operation' => 'create_pull_request',
            'owner' => 'owner',
            'repo' => 'repo',
        ],
        inputData: [
            'title' => 'My PR',
            'body' => 'PR description',
            'head' => 'feature-branch',
            'base' => 'main',
        ],
        credentials: ['access_token' => 'ghp_test123'],
    );

    $result = $node->handle($payload);

    expect($result->output)
        ->toHaveKey('id', 99)
        ->toHaveKey('title', 'My PR');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'repos/owner/repo/pulls')
            && $request['head'] === 'feature-branch'
            && $request['base'] === 'main';
    });
});

it('returns an error for unknown operations', function () {
    $node = new GitHubNode;

    $payload = new NodePayload(
        nodeId: 'test_node',
        nodeType: 'github',
        nodeName: 'GitHub',
        config: ['operation' => 'invalid_op'],
        inputData: [],
        credentials: ['access_token' => 'ghp_test123'],
    );

    $result = $node->handle($payload);

    expect($result->output)->toBeNull()
        ->and($result->error['code'])->toBe('GITHUB_ERROR');
});

<?php

namespace App\Engine\Nodes\Apps\GitHub;

use App\Engine\Nodes\Apps\AppNode;
use App\Engine\Runners\NodePayload;

class GitHubNode extends AppNode
{
    private const BASE_URL = 'https://api.github.com';

    protected function errorCode(): string
    {
        return 'GITHUB_ERROR';
    }

    protected function operations(): array
    {
        return [
            'list_repos' => $this->listRepos(...),
            'create_issue' => $this->createIssue(...),
            'list_issues' => $this->listIssues(...),
            'create_pull_request' => $this->createPullRequest(...),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.github.v3+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRepos(NodePayload $payload): array
    {
        $config = $payload->config;
        $perPage = (int) ($config['per_page'] ?? 30);
        $sort = $config['sort'] ?? 'updated';

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->get(self::BASE_URL.'/user/repos', [
                'per_page' => $perPage,
                'sort' => $sort,
            ]);

        $response->throw();

        return [
            'repos' => $response->json(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createIssue(NodePayload $payload): array
    {
        $config = $payload->config;
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $title = $payload->inputData['title'] ?? $config['title'] ?? '';
        $body = $payload->inputData['body'] ?? $config['body'] ?? '';
        $labels = $payload->inputData['labels'] ?? $config['labels'] ?? [];

        $requestBody = [
            'title' => $title,
            'body' => $body,
        ];

        if ($labels) {
            $requestBody['labels'] = $labels;
        }

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->post(self::BASE_URL."/repos/{$owner}/{$repo}/issues", $requestBody);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function listIssues(NodePayload $payload): array
    {
        $config = $payload->config;
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $state = $config['state'] ?? 'open';
        $perPage = (int) ($config['per_page'] ?? 30);

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->get(self::BASE_URL."/repos/{$owner}/{$repo}/issues", [
                'state' => $state,
                'per_page' => $perPage,
            ]);

        $response->throw();

        return [
            'issues' => $response->json(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPullRequest(NodePayload $payload): array
    {
        $config = $payload->config;
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $title = $payload->inputData['title'] ?? $config['title'] ?? '';
        $body = $payload->inputData['body'] ?? $config['body'] ?? '';
        $head = $payload->inputData['head'] ?? $config['head'] ?? '';
        $base = $payload->inputData['base'] ?? $config['base'] ?? '';

        $response = $this->authenticatedRequest($payload->credentials, $this->getHeaders())
            ->post(self::BASE_URL."/repos/{$owner}/{$repo}/pulls", [
                'title' => $title,
                'body' => $body,
                'head' => $head,
                'base' => $base,
            ]);

        $response->throw();

        return $response->json();
    }
}

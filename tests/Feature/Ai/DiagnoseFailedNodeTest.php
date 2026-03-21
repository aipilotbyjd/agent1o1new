<?php

use App\Ai\Agents\ErrorDiagnosisAgent;
use App\Jobs\DiagnoseFailedNode;
use App\Models\AiFixSuggestion;
use App\Models\Execution;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

uses(RefreshDatabase::class);

it('diagnoses a failed node and creates a suggestion', function () {
    Ai::fakeAgent(ErrorDiagnosisAgent::class, [
        [
            'diagnosis' => 'The API key provided is invalid.',
            'suggestions' => [
                'Check your workspace credentials.',
                'Ensure the OpenAI API key is active.',
            ],
        ],
    ]);

    $workspace = Workspace::factory()->create();
    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id]);
    $execution = Execution::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
    ]);

    $job = new DiagnoseFailedNode(
        executionId: $execution->id,
        failedNodeKey: 'slack_1',
        errorMessage: 'Invalid API Key',
        nodeType: 'slack.send_message',
        nodeConfig: ['channel' => '#general'],
        inputData: ['text' => 'hello']
    );

    $job->handle();

    $suggestion = AiFixSuggestion::query()->where('execution_id', $execution->id)->first();

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->diagnosis)->toBe('The API key provided is invalid.')
        ->and($suggestion->suggestions)->toBeArray()->toHaveCount(2)
        ->and($suggestion->failed_node_key)->toBe('slack_1');
});

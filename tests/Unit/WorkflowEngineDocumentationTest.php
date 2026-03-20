<?php

it('documents the workflow engine execution path and entry points', function () {
    $path = dirname(__DIR__, 2).'/docs/WORKFLOW_ENGINE_GUIDE.md';

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)->not->toBeFalse()
        ->toContain('Workflow Engine Guide')
        ->toContain('ExecutionService::trigger()')
        ->toContain('ExecuteWorkflowJob')
        ->toContain('WorkflowEngine::run()')
        ->toContain('ResumeWorkflowJob')
        ->toContain('WebhookReceiverController')
        ->toContain('PollTriggersCommand')
        ->toContain('ScheduleCronWorkflows')
        ->toContain('The external-engine compatibility layer has been removed.');
});

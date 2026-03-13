<?php

namespace App\Engine\Exceptions;

use RuntimeException;

class ExecutionTimedOutException extends RuntimeException
{
    public function __construct(
        public readonly int $executionId,
        public readonly int $elapsedMs,
        public readonly int $budgetMs,
    ) {
        parent::__construct(
            "Execution [{$executionId}] exceeded time budget: {$elapsedMs}ms / {$budgetMs}ms."
        );
    }
}

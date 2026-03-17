<?php

namespace App\Engine\Data;

use Carbon\CarbonInterface;

/**
 * Represents a node suspension request during async execution.
 *
 * Nodes that implement SuspendsExecution return a Suspension to
 * signal that execution should pause and resume at a later time.
 */
class Suspension
{
    /**
     * @param  array<string, mixed>  $nodeOutput
     */
    public function __construct(
        public readonly string $reason,
        public readonly CarbonInterface $resumeAt,
        public readonly array $nodeOutput = [],
    ) {}
}

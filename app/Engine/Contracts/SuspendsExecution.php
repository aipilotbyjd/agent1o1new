<?php

namespace App\Engine\Contracts;

use App\Engine\Data\Suspension;
use App\Engine\Runners\NodePayload;

interface SuspendsExecution
{
    /**
     * Determine whether the node should suspend and return suspension details.
     */
    public function suspend(NodePayload $payload): Suspension;
}

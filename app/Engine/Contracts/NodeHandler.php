<?php

namespace App\Engine\Contracts;

use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;

interface NodeHandler
{
    /**
     * Execute a single workflow node and return its result.
     */
    public function handle(NodePayload $payload): NodeResult;
}

<?php

namespace App\Engine\Exceptions;

use RuntimeException;

class CycleDetectedException extends RuntimeException
{
    /**
     * @param  list<string>  $involvedNodes
     */
    public function __construct(public readonly array $involvedNodes = [])
    {
        $nodeList = implode(', ', $this->involvedNodes);

        parent::__construct("Workflow graph contains a cycle involving nodes: [{$nodeList}].");
    }
}

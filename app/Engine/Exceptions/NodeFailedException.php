<?php

namespace App\Engine\Exceptions;

use RuntimeException;

class NodeFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $errorData
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $nodeType,
        string $reason,
        public readonly ?array $errorData = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Node [{$nodeId}] ({$nodeType}) failed: {$reason}", 0, $previous);
    }
}

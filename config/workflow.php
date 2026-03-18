<?php

return [
    'async_max_concurrency' => env('WORKFLOW_ASYNC_CONCURRENCY', 4),
    'batch_flush_threshold' => env('WORKFLOW_BATCH_FLUSH_THRESHOLD', 100),
    'batch_flush_interval' => env('WORKFLOW_BATCH_FLUSH_INTERVAL', 1.0),
];

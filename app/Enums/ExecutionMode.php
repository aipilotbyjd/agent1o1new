<?php

namespace App\Enums;

enum ExecutionMode: string
{
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Schedule = 'schedule';
    case Retry = 'retry';
    case Scheduled = 'scheduled';
    case Polling = 'polling';
    case SubWorkflow = 'sub_workflow';
    case Replay = 'replay';
}

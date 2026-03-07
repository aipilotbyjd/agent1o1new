<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Execution = 'execution';
    case AiExecution = 'ai_execution';
    case CodeExecution = 'code_execution';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case PackPurchase = 'pack_purchase';
    case Bonus = 'bonus';
    case Rollover = 'rollover';
}

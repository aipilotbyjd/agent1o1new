<?php

namespace App\Enums;

enum CreditPackStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Refunded = 'refunded';
}

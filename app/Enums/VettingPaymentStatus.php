<?php

namespace App\Enums;

enum VettingPaymentStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Refunded = 'refunded';
    case Void = 'void';
    case Failed = 'failed';
}

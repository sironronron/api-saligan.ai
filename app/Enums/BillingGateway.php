<?php

namespace App\Enums;

enum BillingGateway: string
{
    case Paymongo = 'paymongo';

    case LemonSqueezy = 'lemonsqueezy';
}

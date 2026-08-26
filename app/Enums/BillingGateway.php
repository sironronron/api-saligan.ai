<?php

namespace App\Enums;

enum BillingGateway: string
{
    case Paypal = 'paypal';

    case Paymongo = 'paymongo';

    case LemonSqueezy = 'lemonsqueezy';
}

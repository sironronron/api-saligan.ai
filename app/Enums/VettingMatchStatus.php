<?php

namespace App\Enums;

enum VettingMatchStatus: string
{
    case Notified = 'notified';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Escalated = 'escalated';
}

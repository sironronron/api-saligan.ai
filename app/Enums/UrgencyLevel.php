<?php

namespace App\Enums;

enum UrgencyLevel: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';

    public function label(): string
    {
        return $this === self::Urgent ? 'Urgent' : 'Normal';
    }
}

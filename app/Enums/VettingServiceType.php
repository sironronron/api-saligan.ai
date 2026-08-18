<?php

namespace App\Enums;

enum VettingServiceType: string
{
    case Vetting = 'vetting';
    case Notarization = 'notarization';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Vetting => 'Legal vetting',
            self::Notarization => 'Notarization',
            self::Both => 'Legal vetting + notarization',
        };
    }

    /**
     * Whether the request includes a notarization leg.
     */
    public function includesNotarization(): bool
    {
        return in_array($this, [self::Notarization, self::Both], true);
    }
}

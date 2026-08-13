<?php

namespace App\Enums;

enum LegalSourceCategory: string
{
    case Law = 'law';
    case Jurisprudence = 'jurisprudence';
    case Issuance = 'issuance';
    case Treaty = 'treaty';
    case General = 'general';

    /**
     * The human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Law => 'Philippine Law',
            self::Jurisprudence => 'Jurisprudence',
            self::Issuance => 'Issuance',
            self::Treaty => 'Treaty',
            self::General => 'General',
        };
    }
}

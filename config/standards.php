<?php

return [
    'profiles' => [
        'iso-9001' => [
            'code' => 'ISO 9001',
            'title' => 'Quality management systems — Requirements',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/62085.html',
        ],
        'iso-iec-27001' => [
            'code' => 'ISO/IEC 27001',
            'title' => 'Information security, cybersecurity and privacy protection — Information security management systems — Requirements',
            'issuers' => ['ISO', 'IEC'],
            'reference_url' => 'https://www.iso.org/standard/82875.html',
        ],
        'iso-14001' => [
            'code' => 'ISO 14001',
            'title' => 'Environmental management systems — Requirements with guidance for use',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/60857.html',
        ],
        'iso-45001' => [
            'code' => 'ISO 45001',
            'title' => 'Occupational health and safety management systems — Requirements with guidance for use',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/63787.html',
        ],
        'iso-31000' => [
            'code' => 'ISO 31000',
            'title' => 'Risk management — Guidelines',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/65694.html',
        ],
        'iso-37301' => [
            'code' => 'ISO 37301',
            'title' => 'Compliance management systems — Requirements with guidance for use',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/75080.html',
        ],
        'iso-20022' => [
            'code' => 'ISO 20022',
            'title' => 'Financial services — Universal financial industry message scheme',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/20022-1',
        ],
        'iso-8583' => [
            'code' => 'ISO 8583',
            'title' => 'Financial-transaction-card-originated messages — Interchange message specifications',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/79451.html',
        ],
        'iso-9362' => [
            'code' => 'ISO 9362',
            'title' => 'Banking — Banking telecommunication messages — Business identifier code (BIC)',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/60390.html',
        ],
        'iso-4217' => [
            'code' => 'ISO 4217',
            'title' => 'Codes for the representation of currencies',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/64758.html',
        ],
        'iso-17442' => [
            'code' => 'ISO 17442',
            'title' => 'Financial services — Legal Entity Identifier (LEI)',
            'issuers' => ['ISO'],
            'reference_url' => 'https://www.iso.org/standard/59771.html',
        ],
    ],

    'allowed_statuses' => [
        'current',
        'withdrawn',
        'draft',
        'informational',
    ],

    'allowed_rights_bases' => [
        'licensed_copy',
        'public_summary',
    ],
];

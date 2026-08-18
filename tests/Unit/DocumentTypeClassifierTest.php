<?php

use App\Services\Vetting\DocumentTypeClassifier;

beforeEach(function () {
    $this->classifier = new DocumentTypeClassifier;
});

it('classifies a canonical slug as itself', function (string $slug) {
    expect($this->classifier->slugFor($slug))->toBe($slug);
})->with([
    'contract', 'deed', 'lease', 'power_of_attorney', 'affidavit',
    'complaint', 'demand_letter', 'government_letter', 'corporate',
]);

it('classifies the vetting form suggestions into practice-area slugs', function () {
    expect($this->classifier->slugFor('Deed of Sale'))->toBe('deed')
        ->and($this->classifier->slugFor('Deed of Absolute Sale'))->toBe('deed')
        ->and($this->classifier->slugFor('Deed of Donation'))->toBe('deed')
        ->and($this->classifier->slugFor('Extra-Judicial Settlement'))->toBe('deed')
        ->and($this->classifier->slugFor('Extrajudicial Settlement'))->toBe('deed')
        ->and($this->classifier->slugFor('Contract to Sell'))->toBe('contract')
        ->and($this->classifier->slugFor('Loan Agreement'))->toBe('contract')
        ->and($this->classifier->slugFor('Lease Agreement'))->toBe('lease')
        ->and($this->classifier->slugFor('Contract of Lease'))->toBe('lease')
        ->and($this->classifier->slugFor('Special Power of Attorney'))->toBe('power_of_attorney')
        ->and($this->classifier->slugFor('Board Resolution'))->toBe('corporate')
        ->and($this->classifier->slugFor('Affidavit of Loss'))->toBe('affidavit');
});

it('is case and whitespace insensitive', function () {
    expect($this->classifier->slugFor('  DEED OF SALE  '))->toBe('deed');
});

it('returns null for an empty or unknown document type', function () {
    expect($this->classifier->slugFor(''))->toBeNull()
        ->and($this->classifier->slugFor('Miscellaneous'))->toBeNull();
});

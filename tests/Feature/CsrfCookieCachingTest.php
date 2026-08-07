<?php

it('prevents the sanctum csrf-cookie response from being cached', function () {
    $response = $this->get('/sanctum/csrf-cookie');

    expect($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
});

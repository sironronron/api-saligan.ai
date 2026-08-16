<?php

test('the resend mailer is configured with an api key', function () {
    expect(config('services.resend.key'))->not->toBeEmpty();
});

test('the resend mailer uses the resend transport', function () {
    $mailer = config('mail.mailers.resend');

    expect($mailer['transport'])->toBe('resend');
});

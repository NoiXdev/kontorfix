<?php

use App\Support\AbandonmentNotice;

it('gives Composer the replacement name when there is one', function () {
    expect((new AbandonmentNotice('symfony/mailer', null))->composerValue())
        ->toBe('symfony/mailer');
});

it('gives Composer a bare true when there is no replacement', function () {
    expect((new AbandonmentNotice(null, 'Wird nicht mehr gepflegt.'))->composerValue())
        ->toBe(true);
});

it('uses the reason alone when no replacement is named', function () {
    expect((new AbandonmentNotice(null, 'Wird nicht mehr gepflegt.'))->message())
        ->toBe('Wird nicht mehr gepflegt.');
});

it('appends the replacement hint to the reason', function () {
    expect((new AbandonmentNotice('symfony/mailer', 'Wird nicht mehr gepflegt.'))->message())
        ->toBe('Wird nicht mehr gepflegt. Bitte stattdessen symfony/mailer verwenden.');
});

it('terminates a reason that has no sentence-ending punctuation before appending', function () {
    expect((new AbandonmentNotice('symfony/mailer', 'Wird nicht mehr gepflegt'))->message())
        ->toBe('Wird nicht mehr gepflegt. Bitte stattdessen symfony/mailer verwenden.');
});

it('keeps an existing question or exclamation mark instead of adding a period', function () {
    expect((new AbandonmentNotice('symfony/mailer', 'Warum noch nutzen?'))->message())
        ->toBe('Warum noch nutzen? Bitte stattdessen symfony/mailer verwenden.');
});

it('falls back to a default sentence when no reason is given', function () {
    expect((new AbandonmentNotice('symfony/mailer', null))->message())
        ->toBe('Dieses Paket wird nicht mehr gepflegt. Bitte stattdessen symfony/mailer verwenden.');
});

it('falls back to the default sentence alone when neither field is set', function () {
    expect((new AbandonmentNotice(null, null))->message())
        ->toBe('Dieses Paket wird nicht mehr gepflegt.');
});

it('ignores a reason that is only whitespace', function () {
    expect((new AbandonmentNotice(null, "   \n "))->message())
        ->toBe('Dieses Paket wird nicht mehr gepflegt.');
});

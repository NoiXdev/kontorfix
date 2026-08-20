<?php

use App\Models\Package;
use Illuminate\Support\Carbon;

it('is not abandoned by default', function () {
    expect(Package::factory()->create()->isAbandoned())->toBeFalse();
});

it('reports abandoned once the timestamp is set', function () {
    $package = Package::factory()->create(['abandoned_at' => now()]);

    expect($package->isAbandoned())->toBeTrue();
});

it('hands out no notice while the package is live', function () {
    $package = Package::factory()->create(['replacement_package' => 'symfony/mailer']);

    expect($package->abandonmentNotice())->toBeNull();
});

it('builds the notice from the stored columns', function () {
    $package = Package::factory()->create([
        'abandoned_at' => now(),
        'replacement_package' => 'symfony/mailer',
        'abandonment_reason' => 'Wird nicht mehr gepflegt.',
    ]);

    $notice = $package->abandonmentNotice();

    expect($notice)->not->toBeNull()
        ->and($notice->composerValue())->toBe('symfony/mailer')
        ->and($notice->message())->toBe('Wird nicht mehr gepflegt. Bitte stattdessen symfony/mailer verwenden.');
});

it('casts the timestamp to a date object', function () {
    $package = Package::factory()->create(['abandoned_at' => '2026-08-20 10:00:00']);

    expect($package->fresh()->abandoned_at)->toBeInstanceOf(Carbon::class);
});

it('records the abandonment in the activity log', function () {
    $package = Package::factory()->create();

    $package->update([
        'abandoned_at' => now(),
        'replacement_package' => 'symfony/mailer',
    ]);

    $activity = $package->activitiesAsSubject()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes['attributes'])->toHaveKey('abandoned_at')
        ->and($activity->attribute_changes['attributes']['replacement_package'])->toBe('symfony/mailer');
});

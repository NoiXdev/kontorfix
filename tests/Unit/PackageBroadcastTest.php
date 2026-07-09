<?php

use App\Events\PackageSynced;
use App\Models\Package;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts package sync on the private operator channel with the package payload', function () {
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $event = new PackageSynced($pkg);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toBeInstanceOf(PrivateChannel::class);
    expect($event->broadcastOn()->name)->toBe('private-operator');
    expect($event->broadcastWith())->toMatchArray(['name' => 'acme/widget']);
});

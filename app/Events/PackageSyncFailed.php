<?php

namespace App\Events;

use App\Models\Package;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PackageSyncFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Package $package, public string $error) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('operator');
    }

    public function broadcastAs(): string
    {
        return 'package.sync_failed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->package->id,
            'name' => $this->package->name,
            'type' => $this->package->type->value,
            'sync_status' => $this->package->sync_status->value,
            'error' => $this->error,
        ];
    }
}

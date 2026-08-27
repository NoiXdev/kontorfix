<?php

namespace App\Events;

use App\Models\Package;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PackageSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Seconds this event's queued broadcast delivery may run before the worker kills it.
     *
     * `ShouldBroadcast` (unlike `ShouldBroadcastNow`) queues
     * `Illuminate\Broadcasting\BroadcastEvent` — a `ShouldQueue` job in its own right — onto
     * the `default` queue. `BroadcastEvent::__construct()` reads this property straight off
     * the event (via `ReadsQueueAttributes::getAttributeValue()`), and
     * `Worker::timeoutForJob()` falls back to the *supervisor's* timeout for any job that
     * declares none. `config/horizon.php` raises `supervisor-1.timeout` to
     * `SyncPackage::TIMEOUT` (900s) for SyncPackage's own kill paths — without this property
     * every sync, successful or finally failed, silently inherited that fifteen-minute worker
     * alarm for what is a fire-and-forget Pusher broadcast. `pusher/pusher-php-server` builds
     * its Guzzle client with a 30s timeout, so 30s here is generous headroom, not a tight fit.
     */
    public int $timeout = 30;

    public function __construct(public Package $package) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('operator');
    }

    public function broadcastAs(): string
    {
        return 'package.synced';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->package->id,
            'name' => $this->package->name,
            'type' => $this->package->type->value,
            'sync_status' => $this->package->sync_status->value,
        ];
    }
}

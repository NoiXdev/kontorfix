<?php

namespace App\Services\Portal;

use App\Models\Group;
use Illuminate\Support\Carbon;

/**
 * One registry's answer about one package: which registry, whether the assignment is in force
 * there, and when it ends.
 *
 * A typed object rather than a three-key array, for two reasons. The consumer's: the properties
 * are discoverable, and a misspelling is an error at analysis time instead of a silent null in a
 * German badge. The tooling's: PHPStan rejects a nullable union nested beneath
 * Illuminate\Support\Collection's INVARIANT TValue, so `available_until: Carbon|null` inside an
 * array shape made a return type identical to its own declaration unprovable — measured, and
 * bisected to the union. The nullable lives inside this class, where nothing has to compare two
 * generic shapes, and PortalPackages needs no suppression.
 *
 * The property names are snake_case ON PURPOSE, against the usual PHP habit: they are the payload
 * keys the portal page receives, and Collection::pluck('in_force') / pluck('available_until') read
 * them through data_get, so the shape a test names, the shape the controller maps and the shape
 * the page renders stay the same three words.
 *
 * `available_until` is a STORED VALUE CARRIED, not a rule. Nothing here or in PortalPackages
 * compares it to anything; whether an assignment is in force is decided one single way, by
 * RegistryAccessService, and arrives here already answered in `in_force`.
 */
final class PortalRegistryAssignment
{
    public function __construct(
        public readonly Group $group,
        public readonly bool $in_force,
        public readonly ?Carbon $available_until,
    ) {}
}

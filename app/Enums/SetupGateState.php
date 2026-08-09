<?php

namespace App\Enums;

/**
 * The three states the first-run wizard's token gate can be in. Modelled explicitly
 * because "no token stored" and "token not presented" used to collapse into the same
 * falsy value, and the gate then picked the wrong one of them.
 */
enum SetupGateState: string
{
    /** No token is demanded here (local development / tests) — the wizard is open. */
    case Open = 'open';

    /** A token is demanded and has not been presented. Nothing may be written. */
    case Locked = 'locked';

    /** A token is demanded and was presented correctly. */
    case Unlocked = 'unlocked';
}

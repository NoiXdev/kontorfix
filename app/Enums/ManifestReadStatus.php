<?php

namespace App\Enums;

/**
 * How the "add package" probe's second job — reading the package manifest to discover the
 * name and description — actually went.
 *
 * It exists because the probe used to collapse every manifest outcome into a null name on
 * an otherwise successful result. „Repository erreichbar" with an empty name field then
 * meant either "this repository genuinely has no manifest" (type the name yourself) or "we
 * could not read it" (fix access first) — two different jobs for the operator, reported
 * identically, so the second one was invisible.
 */
enum ManifestReadStatus: string
{
    /** The manifest was fetched and parsed. The name may still be absent from its contents. */
    case Ok = 'ok';

    /** The repository has no such file at the probed commit. Nothing is wrong. */
    case Missing = 'missing';

    /** The file should be there but could not be fetched or parsed — see the log for why. */
    case Unreadable = 'unreadable';
}

<?php

namespace App\Enums;

enum UpstreamPolicy: string
{
    case Proxy = 'proxy';    // alles durchleiten & cachen
    case Strict = 'strict';  // nur Allowlist (Schutz vor Dependency Confusion)
}

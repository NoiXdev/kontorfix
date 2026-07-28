<?php

namespace App\Enums;

enum UpstreamPolicy: string
{
    case Proxy = 'proxy';    // pass through and cache everything
    case Strict = 'strict';  // allowlist only (protection against dependency confusion)
}

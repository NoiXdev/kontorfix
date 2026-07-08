<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case PackageSynced = 'package.synced';
    case SyncFailed = 'sync.failed';
    case VersionReleased = 'version.released';
}

<?php

namespace App\Services\Registry;

use App\Models\Group;

class SetupSnippetBuilder
{
    public function __construct(private RegistryUrl $url) {}

    /**
     * @return array{composer: string, auth: string, npm: string}
     */
    public function for(Group $group): array
    {
        $base = $this->url->base($group);
        $host = $this->url->host($group);
        $prefix = $this->url->pathPrefix($group);
        // npm-Zeilen adressieren den Host inkl. Pfad-Präfix; mit Slash abgeschlossen.
        $npmBase = $host.$prefix.'/';

        return [
            'composer' => json_encode([
                'repositories' => [
                    ['type' => 'composer', 'url' => $base],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

            'auth' => json_encode([
                'http-basic' => [
                    $host => ['username' => 'token', 'password' => '<dein-token>'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

            'npm' => "registry={$base}/\n//{$npmBase}:_authToken=<dein-token>",
        ];
    }
}

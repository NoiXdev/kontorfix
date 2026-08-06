<?php

namespace App\Services\Registry;

use App\Models\Group;

class SetupSnippetBuilder
{
    public function __construct(private RegistryUrl $url) {}

    /**
     * Copy-paste setup snippets per client. Composer/npm/auth as before, plus pip and
     * twine for the Python registry.
     *
     * @return array{composer: string, auth: string, npm: string, pip: string, twine: string}
     */
    public function for(Group $group): array
    {
        $base = $this->url->base($group);
        $host = $this->url->host($group);
        $prefix = $this->url->pathPrefix($group);
        // npm lines address the host including the path prefix; terminated with a slash.
        $npmBase = $host.$prefix.'/';
        $simple = $base.'/simple/';
        // The same URL with inline credentials — the shape pip understands out of the box.
        $simpleAuth = 'https://token:<dein-token>@'.$host.$prefix.'/simple/';

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

            // pip: an index-url one-liner (inline token) plus a pip.conf block.
            'pip' => "pip install --index-url {$simpleAuth} <paket>\n\n# oder dauerhaft in ~/.config/pip/pip.conf:\n[global]\nindex-url = {$simpleAuth}",

            // twine: a ~/.pypirc block pointing publishes at this registry.
            'twine' => "[distutils]\nindex-servers = kontorfix\n\n[kontorfix]\nrepository = {$base}/\nusername = token\npassword = <dein-token>",
        ];
    }
}

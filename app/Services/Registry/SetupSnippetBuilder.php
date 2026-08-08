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
        // The same URL with inline credentials. pip understands no other shape on the
        // command line, so the one-liner keeps it — but see the pip.conf block below: a
        // credential belongs in ~/.netrc (mode 600, never committed), not in a config
        // file that tends to end up in a repository. Inline credentials in a URL are also
        // what leads operators to put a mirror password into an upstream URL, where the
        // application then has to withhold it from readers (see App\Support\CredentialUrl).
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

            // pip: an index-url one-liner (inline token, the only form pip accepts on the
            // command line) plus a persistent setup that keeps the token out of pip.conf.
            'pip' => "pip install --index-url {$simpleAuth} <paket>\n\n"
                ."# oder dauerhaft — Token in ~/.netrc (chmod 600), nicht in pip.conf:\n"
                ."# ~/.config/pip/pip.conf:\n[global]\nindex-url = {$simple}\n\n"
                ."# ~/.netrc:\nmachine {$host}\n  login token\n  password <dein-token>",

            // twine: a ~/.pypirc block pointing publishes at this registry.
            'twine' => "[distutils]\nindex-servers = kontorfix\n\n[kontorfix]\nrepository = {$base}/\nusername = token\npassword = <dein-token>",
        ];
    }
}

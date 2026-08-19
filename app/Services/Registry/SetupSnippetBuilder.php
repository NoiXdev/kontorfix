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

            'npm' => $this->npm($group),

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

    /**
     * The npm block, scoped rather than global.
     *
     * A bare `registry=` line routes EVERY package through this registry, which means every
     * public dependency has to come back through an upstream proxy — more load, and one
     * upstream failure breaks installs that have nothing to do with this registry. A
     * `@scope:registry=` line routes only the packages that actually live here.
     *
     * The path form is listed first because it works the moment the registry is reachable.
     * The domain form follows as the production shape, and only when a domain is configured
     * — it needs DNS that a fresh install does not have yet.
     */
    private function npm(Group $group): string
    {
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $pathUrl = rtrim((string) config('app.url'), '/').'/r/'.$group->slug.'/';
        $pathAuthority = $appHost.'/r/'.$group->slug.'/';

        $block = "# Pfad-Variante — funktioniert sofort, ohne DNS-Eintrag\n"
            .$this->npmLines($group, $pathUrl, $pathAuthority);

        $domain = $group->domains->first();
        if ($domain !== null) {
            $domainUrl = 'https://'.$domain->hostname.'/';
            $block .= "\n\n# Domain-Variante — empfohlen im Betrieb, sobald der DNS-Eintrag steht\n"
                .$this->npmLines($group, $domainUrl, $domain->hostname.'/');
        }

        return $block;
    }

    /**
     * One registry line per scope found among this group's npm packages, plus the auth line.
     *
     * Scopes are derived from the package names (`@scope/name`) rather than configured, so
     * they cannot drift from what the registry actually serves. A group with no scoped npm
     * package yet gets a placeholder: a `@scope` that does not exist would silently route
     * nothing, which is worse than an obvious blank to fill in.
     */
    private function npmLines(Group $group, string $url, string $authority): string
    {
        $scopes = $group->packages()
            ->where('type', 'npm')
            ->where('name', 'like', '@%')
            ->pluck('name')
            ->map(fn (string $name): string => explode('/', $name)[0])
            ->unique()
            ->sort()
            ->values();

        if ($scopes->isEmpty()) {
            $scopes = collect(['@<dein-scope>']);
        }

        $lines = $scopes->map(fn (string $scope): string => "{$scope}:registry={$url}")->implode("\n");

        return $lines."\n//{$authority}:_authToken=<dein-token>";
    }
}

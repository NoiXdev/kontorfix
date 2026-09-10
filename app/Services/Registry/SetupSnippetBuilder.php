<?php

namespace App\Services\Registry;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Services\RegistryAccessService;
use Illuminate\Support\Collection;

class SetupSnippetBuilder
{
    public function __construct(
        private RegistryUrl $url,
        private RegistryTypeService $types,
        private RegistryAccessService $access,
    ) {}

    /**
     * The command a consumer runs for ONE package in THIS registry — the whole of what the
     * portal's package page and its package list print, and the only place any of them is
     * assembled.
     *
     * IT REPLACES `PackageType::installHint()`, WHICH IS GONE, and the removal is the point
     * of the method rather than a tidy-up alongside it. That hint built a registry-less
     * command for every ecosystem and the portal rendered it. For Composer and npm the
     * result merely fails in an unconfigured project, visibly and at once. For Python it does
     * NOT fail: `pip install kernmodul` without `--index-url` resolves against PyPI, and if a
     * package of that name exists there, pip installs THAT — into a customer's build, with no
     * error anywhere. A command that silently fetches a stranger's code is not a rough edge,
     * and leaving the generator in place "unused" is how it comes back.
     *
     * Only pip and Docker carry the address, and that is deliberate rather than an omission:
     * `composer require` and `npm install` take no registry argument at all — their registry
     * is configured once, in `composer.json`/`.npmrc`, which is exactly what the setup tab
     * above them is for and what the package page's prerequisite line names. Printing a flag
     * those two clients do not have would be a command that cannot be copied.
     *
     * `$tag` is Docker-only and optional. A real tag when the repository has one (the plate's
     * `docker pull images.3b.de/meinapp:1.4.0`), and NOTHING when it has none — never a
     * `<tag>` placeholder here, unlike `dockerSetup.ts`, which prints one because its snippet
     * describes a repository that may not exist yet. This command names a repository the
     * reader is looking at, so `docker pull <host>/<repo>` is both true and runnable (Docker
     * resolves it as `:latest`), where a placeholder would be neither.
     */
    public function installCommand(Group $group, PackageType $type, string $name, ?string $tag = null): string
    {
        return match ($type) {
            PackageType::Composer => "composer require {$name}",
            PackageType::Npm => "npm install {$name}",
            // The same URL the `pip` setup snippet's one-liner uses, from the same private
            // method — inline credentials because pip accepts no other shape on the command
            // line (see simpleAuthUrl()).
            PackageType::Python => 'pip install --index-url '.$this->simpleAuthUrl($group)." {$name}",
            PackageType::Docker => 'docker pull '.$this->url->dockerImagePrefix($group).'/'.$name
                .($tag === null ? '' : ':'.$tag),
        };
    }

    /**
     * Copy-paste setup snippets per client. Composer/npm/auth as before, plus pip and
     * twine for the Python registry, and four raw facts for the Docker step.
     *
     * The Docker fields deliberately are NOT a finished snippet, unlike every field above
     * them. `dockerSetup.ts` (resources/js/components/kontorfix/) is what assembles the
     * `docker login`/`pull`/`tag`/`push` block and the note that goes under it — this
     * method's job stops at supplying the facts, because that assembly is the one piece of
     * RegistrySetup's logic this project can actually unit test (no component runner here),
     * and duplicating the German copy on this side would only give it a second place to
     * drift from.
     *
     * @return array{composer: string, auth: string, npm: string, pip: string, twine: string,
     *     dockerHost: string, dockerRepositoryPrefix: string, dockerHasDomain: bool,
     *     dockerExample: ?string}
     */
    public function for(Group $group): array
    {
        $base = $this->url->base($group);
        $host = $this->url->host($group);
        $prefix = $this->url->pathPrefix($group);
        // npm lines address the host including the path prefix; terminated with a slash.
        $npmBase = $host.$prefix.'/';
        $simple = $base.'/simple/';
        $simpleAuth = $this->simpleAuthUrl($group);

        return [
            'composer' => json_encode([
                'repositories' => [
                    ['type' => 'composer', 'url' => $base],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

            'auth' => json_encode([
                'http-basic' => [
                    $host => ['username' => 'token', 'password' => '<token>'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

            'npm' => $this->npm($group),

            // pip: an index-url one-liner (inline token, the only form pip accepts on the
            // command line) plus a persistent setup that keeps the token out of pip.conf.
            'pip' => "pip install --index-url {$simpleAuth} <paket>\n\n"
                ."# oder dauerhaft — Token in ~/.netrc (chmod 600), nicht in pip.conf:\n"
                ."# ~/.config/pip/pip.conf:\n[global]\nindex-url = {$simple}\n\n"
                ."# ~/.netrc:\nmachine {$host}\n  login token\n  password <token>",

            // twine: a ~/.pypirc block pointing publishes at this registry.
            'twine' => "[distutils]\nindex-servers = kontorfix\n\n[kontorfix]\nrepository = {$base}/\nusername = token\npassword = <token>",

            // The host a `docker login` addresses, and the namespace a repository name
            // carries in front of it there. Both come from RegistryUrl, which is the one
            // place a registry's address is computed — never assembled here and never a
            // second time in Vue.
            //
            // `dockerHost` used to be nullable, because the OCI Distribution Spec puts
            // `/v2/` at the root of a host and a registry without a domain of its own had
            // nowhere to put it. ResolveOciContext ended that: the instance's own host
            // serves `/v2/` as well, with the two slugs as the leading segments of the
            // repository name. There is no registry left that cannot serve images, so
            // there is no null left to hand out.
            'dockerHost' => $this->url->dockerHost($group),
            'dockerRepositoryPrefix' => $this->url->dockerRepositoryPrefix($group),

            // Not derived from the prefix being empty on the reading end: what the note
            // under the snippet says is a statement about the DOMAIN, and a boolean that
            // says so directly cannot be misread as "this address is shorter for some
            // other reason". dockerSetup.ts turns it into the one sentence that differs
            // between the operator and the customer.
            'dockerHasDomain' => $group->domains->isNotEmpty(),

            // The same "derive from what already exists" rule npm's scope line follows
            // (see npmLines() below): a real repository name when this registry already
            // hosts a Docker package, so the copy-pasted commands work unmodified. Null,
            // not a fabricated name, when it does not — dockerSetup.ts fills in a generic
            // placeholder for that case, the same way it fills in <token> to be replaced.
            'dockerExample' => $group->packages()->where('type', 'docker')->orderBy('name')->value('name'),
        ];
    }

    /**
     * The `/simple/` index URL with inline credentials — the one statement of it, read both
     * by the setup tab's pip one-liner and by `installCommand()`'s Python case. Two spellings
     * of this URL is exactly how a package page ends up offering a command that points
     * somewhere the setup instructions do not.
     *
     * pip understands no other shape on the command line, so the one-liner keeps the inline
     * credential — but see the pip.conf block in for(): a credential belongs in ~/.netrc
     * (mode 600, never committed), not in a config file that tends to end up in a repository.
     * Inline credentials in a URL are also what leads operators to put a mirror password into
     * an upstream URL, where the application then has to withhold it from readers (see
     * App\Support\CredentialUrl).
     *
     * IT IS BUILT FROM `base()`, THE SAME `{$base}/simple/` for()'s pip.conf line prints, with
     * the credentials pushed into the authority — never re-spelled from a scheme and a host.
     * The re-spelling was `'https://'.host().pathPrefix()`, and it was wrong twice on any
     * instance not served over https on :443: it hardcoded the scheme, and `host()` is
     * parse_url's PHP_URL_HOST, which DROPS the port. On `app.url = http://localhost:8099` one
     * response then carried three spellings of one registry — `https://…@localhost/…` in the
     * install command, `http://localhost:8099/…` in the pip.conf line beside it, and
     * `localhost:8099/…` in the Docker command, which keeps its port because dockerHost()
     * already learned this lesson. Two of the three pointed nowhere.
     */
    private function simpleAuthUrl(Group $group): string
    {
        return $this->authUrlFor($this->url->base($group).'/simple/');
    }

    /**
     * The credential-carrying form of any `/simple/` URL — the shared second half of
     * simpleAuthUrl() above, factored out so forOrganization() can push the SAME inline-auth
     * transform through the org base URL rather than re-deriving it from a Group it does not
     * have.
     */
    private function authUrlFor(string $simple): string
    {
        // After the scheme separator, before the host: the one place a URL takes credentials.
        // base()/origin() always name a scheme (a custom domain is `https://…`, otherwise
        // app.url's origin); the guard is for a misconfigured app.url rather than a shape
        // produced here.
        $afterScheme = strpos($simple, '://');

        return $afterScheme === false
            ? 'token:<token>@'.$simple
            : substr($simple, 0, $afterScheme + 3).'token:<token>@'.substr($simple, $afterScheme + 3);
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
        $pathUrl = rtrim((string) config('app.url'), '/').$this->url->path($group).'/';
        $pathAuthority = $appHost.$this->url->path($group).'/';

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
        $names = $group->packages()
            ->where('type', 'npm')
            ->where('name', 'like', '@%')
            ->pluck('name');

        return $this->scopeLines($names, $url, $authority);
    }

    /**
     * The org-wide twin of npm(): one `@scope:registry=` line per scope found among EVERY
     * npm package the org-wide token can reach, plus the auth line — otherwise identical.
     *
     * No domain variant, unlike npm(): custom domains are a Group concern
     * (RegistryUrl::base()'s `domains` relation lives on Group, not Organization), and the
     * org endpoint has no address of its own beyond the instance host (see
     * ResolvesRegistryPackage::registryBaseUrlForOrganization()'s doc comment on the same
     * non-goal).
     */
    private function npmForOrganization(Organization $organization, string $path): string
    {
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $url = rtrim((string) config('app.url'), '/').$path.'/';
        $authority = $appHost.$path.'/';

        $names = $this->access->packagesForOrganization($organization)
            ->where('type', PackageType::Npm)
            ->pluck('name');

        return $this->scopeLines($names, $url, $authority);
    }

    /**
     * One registry line per scope found among the given npm package names, plus the auth
     * line — the one statement of the rule shared by npm() (a single group's packages) and
     * npmForOrganization() (every package the org-wide token can reach).
     *
     * Scopes are derived from the package names (`@scope/name`) rather than configured, so
     * they cannot drift from what the registry actually serves. No scoped npm package yet
     * gets a placeholder: a `@scope` that does not exist would silently route nothing, which
     * is worse than an obvious blank to fill in.
     *
     * @param  Collection<int, string>  $names
     */
    private function scopeLines(Collection $names, string $url, string $authority): string
    {
        $scopes = $names
            ->filter(fn (string $name): bool => str_starts_with($name, '@'))
            ->map(fn (string $name): string => explode('/', $name)[0])
            ->unique()
            ->sort()
            ->values();

        if ($scopes->isEmpty()) {
            $scopes = collect(['@<scope>']);
        }

        $lines = $scopes->map(fn (string $scope): string => "{$scope}:registry={$url}")->implode("\n");

        return $lines."\n//{$authority}:_authToken=<token>";
    }

    /**
     * Copy-paste setup snippets for the org-wide token — the `/o/{orgSlug}` twin of for(),
     * built for the portal's organization-level setup tab (Task 7).
     *
     * Three differences from for(Group), all forced by what the org endpoint actually is:
     *
     * 1. NO twine section, ever — not even when Python is enabled. Publishing targets ONE
     *    registry (PypiController::upload() resolves a project through a single group's
     *    assignments), and the org-wide mount registers the write routes as a 405 for
     *    exactly that reason (see routes/registry.php's `$registryEndpoints(true, …)` call).
     *    A ~/.pypirc block pointed at `/o/{slug}/` would be a snippet for an endpoint that
     *    refuses every upload sent to it.
     *
     * 2. The Docker step is replaced outright rather than merely re-addressed. The org
     *    endpoint does not serve Docker at all (deliberate: an image reference under `/o/`
     *    would be ambiguous about which of the organization's registries it names), so
     *    there is no single `docker pull` this method could print. Instead: every one of the
     *    organization's groups, each with the SAME Docker facts installCommand() would use
     *    for it — its own `dockerHost` (RegistryUrl::dockerHost()) alongside its
     *    `dockerRepositoryPrefix` — so the reader can address whichever registry they
     *    actually mean. Collection groups (portal_enabled=false) are included, since the
     *    org-wide token reaches them exactly as it reaches a portal-visible one, and the
     *    flag is what lets Task 7 label them "Sammlung" instead of hiding them.
     *
     *    A per-entry `dockerHost` — NOT one shared value substituted for every entry — is
     *    the fix for a defect a review caught before Task 7 ever rendered this: a
     *    domain-bound group has an EMPTY `dockerRepositoryPrefix` (its own domain is the
     *    registry root, exactly as for(Group) states it), so pairing it with the shared
     *    instance host would print a `docker login`/`pull` against a host that group is not
     *    served from at all. The top-level `dockerHost` stays as the instance's shared
     *    authority — every domain-less entry's own `dockerHost` equals it, so it still
     *    reads as "the address to use unless a row says otherwise" — but no consumer may
     *    build a command from the top-level value and a per-entry prefix; the two must be
     *    read together from the SAME entry.
     *
     * 3. Every remaining section is gated by RegistryTypeService::effectiveFor() — for(),
     *    by contrast, always returns all of its keys and leaves the gating to the `types`
     *    prop RegistrySetup.vue already filters by. There is no such prop here: Task 7's
     *    payload IS the snippet set, so a disabled ecosystem's key must be ABSENT rather
     *    than merely unfiltered, the same way EnsureRegistryTypeEnabled makes the endpoint
     *    itself behave as if it did not exist.
     *
     * @return array{composer?: string, auth?: string, npm?: string, pip?: string,
     *     dockerHost?: string, dockerGroups?: list<array{name: string, slug: string,
     *     portal_enabled: bool, dockerHost: string, dockerRepositoryPrefix: string}>}
     */
    public function forOrganization(Organization $organization): array
    {
        $enabled = $this->types->effectiveFor($organization);
        $composerEnabled = in_array(PackageType::Composer->value, $enabled, true);
        $npmEnabled = in_array(PackageType::Npm->value, $enabled, true);
        $pythonEnabled = in_array(PackageType::Python->value, $enabled, true);
        $dockerEnabled = in_array(PackageType::Docker->value, $enabled, true);

        $path = $this->url->orgPath($organization);
        $base = $this->url->origin().$path;
        $host = (string) parse_url($base, PHP_URL_HOST);
        $simple = $base.'/simple/';

        $snippets = [];

        if ($composerEnabled) {
            $snippets['composer'] = json_encode([
                'repositories' => [
                    ['type' => 'composer', 'url' => $base],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Shared by composer and npm — the same http-basic block for(Group) prints, present
        // whenever either of the two ecosystems it serves is.
        if ($composerEnabled || $npmEnabled) {
            $snippets['auth'] = json_encode([
                'http-basic' => [
                    $host => ['username' => 'token', 'password' => '<token>'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if ($npmEnabled) {
            $snippets['npm'] = "# Pfad-Variante — funktioniert sofort, ohne DNS-Eintrag\n"
                .$this->npmForOrganization($organization, $path);
        }

        if ($pythonEnabled) {
            $simpleAuth = $this->authUrlFor($simple);
            $snippets['pip'] = "pip install --index-url {$simpleAuth} <paket>\n\n"
                ."# oder dauerhaft — Token in ~/.netrc (chmod 600), nicht in pip.conf:\n"
                ."# ~/.config/pip/pip.conf:\n[global]\nindex-url = {$simple}\n\n"
                ."# ~/.netrc:\nmachine {$host}\n  login token\n  password <token>";
        }

        if ($dockerEnabled) {
            $snippets['dockerHost'] = $this->url->instanceDockerHost();
            $snippets['dockerGroups'] = $organization->groups()
                ->with('domains')
                ->orderBy('name')
                ->get()
                // organization is already known — set rather than lazy-loaded per group,
                // since dockerRepositoryPrefix() reads $group->organization->slug.
                ->each(fn (Group $g) => $g->setRelation('organization', $organization))
                ->map(fn (Group $g): array => [
                    'name' => $g->name,
                    'slug' => $g->slug,
                    'portal_enabled' => $g->portal_enabled,
                    // Own host AND own prefix, read together — never the top-level
                    // dockerHost paired with this prefix (see the doc comment above): a
                    // domain-bound group's prefix is empty precisely because ITS OWN host
                    // is the registry root, not because the instance host is.
                    'dockerHost' => $this->url->dockerHost($g),
                    'dockerRepositoryPrefix' => $this->url->dockerRepositoryPrefix($g),
                ])
                ->values()
                ->all();
        }

        return $snippets;
    }
}

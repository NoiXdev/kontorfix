<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GitProvider;
use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Models\GitCredential;
use App\Services\Scope\OrgScope;
use App\Services\Vcs\RepositoryProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages reusable git access credentials (tokens), scoped to the organizations the user
 * administers. Tokens are write-only from the UI (encrypted at rest, never returned).
 */
class GitCredentialController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        return Inertia::render('admin/git-credentials/Index', [
            'credentials' => GitCredential::with('organization:id,name')
                ->withCount('packages')
                ->whereIn('organization_id', $this->scopedOrgIds())
                ->orderBy('name')->get()
                ->map(fn (GitCredential $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'provider' => $c->provider->value,
                    'host' => $c->allowedHost(),
                    'username' => $c->username,
                    'organization' => $c->organization?->name,
                    'organization_id' => $c->organization_id,
                    'packages_count' => $c->packages_count,
                    'last_used_at' => $c->last_used_at?->diffForHumans(),
                ]),
            'organizations' => app(OrgScope::class)->organizations(),
            'providers' => GitProvider::metadata(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'provider' => ['required', Rule::enum(GitProvider::class)],
            'host' => $this->hostRules($request),
            'username' => ['nullable', 'string', 'max:190'],
            'token' => ['required', 'string', 'max:500'],
        ]);

        GitCredential::create([
            'organization_id' => $this->resolveCreationOrg($data['organization_id'] ?? null),
            'name' => $data['name'],
            'provider' => $data['provider'],
            'host' => $this->resolveHost($data),
            'username' => $data['username'] ?? null,
            'token' => $data['token'],
        ]);

        return back()->with('success', 'Git-Token gespeichert.');
    }

    public function update(Request $request, GitCredential $gitCredential): RedirectResponse
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'provider' => ['required', Rule::enum(GitProvider::class)],
            'host' => $this->hostRules($request),
            'username' => ['nullable', 'string', 'max:190'],
            // Blank = keep the existing token.
            'token' => ['nullable', 'string', 'max:500'],
        ]);

        // Retargeting a credential at a different host while keeping the stored token
        // would hand that token to the new host — the secret must be re-entered, which
        // only someone who already knows it can do.
        $host = $this->resolveHost($data);
        if ($host !== $gitCredential->allowedHost() && empty($data['token'])) {
            throw ValidationException::withMessages([
                'host' => 'Beim Wechsel des Hosts muss der Token neu eingegeben werden.',
            ]);
        }

        $gitCredential->fill([
            'name' => $data['name'],
            'provider' => $data['provider'],
            'host' => $host,
            'username' => $data['username'] ?? null,
        ]);
        if (! empty($data['token'])) {
            $gitCredential->token = $data['token'];
        }
        $gitCredential->save();

        return back()->with('success', 'Git-Token aktualisiert.');
    }

    public function destroy(GitCredential $gitCredential): RedirectResponse
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $gitCredential->delete();

        return back()->with('success', 'Git-Token gelöscht.');
    }

    /** Verifies the credential can reach a given repository (git ls-remote). */
    public function test(Request $request, GitCredential $gitCredential, RepositoryProbe $probe): JsonResponse
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $data = $request->validate([
            'repository_url' => ['required', 'string', 'max:500', 'url:https', 'starts_with:https://'],
        ]);

        // The token may only ever be sent to the host the credential is bound to.
        if (! $gitCredential->permits($data['repository_url'])) {
            throw ValidationException::withMessages([
                'repository_url' => $gitCredential->hostMismatchMessage(),
            ]);
        }

        $result = $probe->probe(
            PackageType::Composer,
            $data['repository_url'],
            $gitCredential->token,
            $gitCredential->provider,
            $gitCredential->username,
        );

        return response()->json(['ok' => $result['ok'], 'error' => $result['error'] ?? null]);
    }

    /**
     * A self-hosted ("generic") credential has no canonical host, so it must name one;
     * for the known providers the host defaults to that provider's own.
     *
     * @return array<int, string>
     */
    private function hostRules(Request $request): array
    {
        $required = GitProvider::tryFrom((string) $request->input('provider')) === GitProvider::Generic;

        // An optional port is part of the binding: permits() compares the whole authority,
        // so a self-hosted git server on a non-default port has to be nameable here.
        return [$required ? 'required' : 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._-]+(:\d{1,5})?$/'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveHost(array $data): ?string
    {
        $host = strtolower(trim((string) ($data['host'] ?? '')));

        return $host !== '' ? $host : GitProvider::from((string) $data['provider'])->defaultHost();
    }
}

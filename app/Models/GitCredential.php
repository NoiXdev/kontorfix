<?php

namespace App\Models;

use App\Enums\GitProvider;
use App\Support\RepositoryAuthority;
use Database\Factories\GitCredentialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable git access token, scoped to an organization, assignable to packages for
 * syncing private repositories. The token is encrypted at rest and never serialised.
 *
 * @property string $name
 * @property GitProvider $provider
 * @property string|null $host
 * @property string|null $username
 * @property string $token
 * @property Carbon|null $last_used_at
 * @property bool $is_global
 */
class GitCredential extends Model
{
    /** @use HasFactory<GitCredentialFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'provider',
        'host',
        'username',
        'token',
        'last_used_at',
        'is_global',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token',
    ];

    /**
     * Stated here as well as in the migration's column default, for the reason
     * SystemSetting gives: a freshly constructed model must carry a truthful flag rather
     * than null until it has been read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_global' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
            // Usable by every organization, read-only. The accepted risk and its one
            // mitigation (permits() binds the token to one host) are recorded on the
            // migration; this flag and the targeted shares below answer the same question
            // through usableBy().
            'is_global' => 'bool',
        ];
    }

    /**
     * The organizations this credential has been explicitly shared to, beside the global
     * flag. Only the operator's own credentials are ever shared or global; a non-owner
     * gets use (the sync transmits the token), never sight (hidden keeps the secret out of
     * every serialisation) and never mutation.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function sharedOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'git_credential_organization');
    }

    /**
     * Whether $organization may USE this credential: it owns it, or the credential is
     * global, or it was shared to it. Checked at SYNC time as well as at assignment time —
     * un-sharing must end the grant for the next sync, not only for the next dropdown.
     *
     * `is`-prefixed so the name cannot collide with scopeUsableBy() below: Eloquent routes
     * the static `GitCredential::usableBy(...)` through __callStatic onto a fresh instance,
     * where an instance method of the same name would intercept the scope.
     */
    public function isUsableBy(Organization $organization): bool
    {
        if ($this->organization_id === $organization->id || $this->is_global) {
            return true;
        }

        return $this->sharedOrganizations()->whereKey($organization->id)->exists();
    }

    /**
     * The credentials $organization may use — the package form's dropdown. One definition
     * with usableBy() above, asserted against it in the schema test so the two answers
     * cannot drift.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableBy(Builder $query, Organization $organization): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('organization_id', $organization->id)
            ->orWhere('is_global', true)
            ->orWhereHas('sharedOrganizations', fn (Builder $shared) => $shared->whereKey($organization->id)));
    }

    /**
     * The single host this credential's token may be transmitted to. Null means "unknown",
     * and unknown means unusable — never "usable everywhere".
     *
     * There used to be a fallback to `$this->provider->defaultHost()` here, and it turned
     * the one refusal the system makes on its own into an assertion. The `host` backfill
     * deliberately leaves a credential unbound when its assigned packages point somewhere
     * other than the canonical host: that is a self-hosted GHE or GitLab PAT, the migration
     * has no safe way to learn its address, and it warns instead of guessing. The fallback
     * then bound exactly those rows to github.com / gitlab.com / bitbucket.org, so the
     * self-hosted PAT was transmitted to the public provider — useless there, and disclosed.
     *
     * Removing it costs nothing legitimate: `GitCredentialController::resolveHost()`
     * materialises the provider default into the column on create and on update, so every
     * credential entered through the console names its host. An empty column is precisely
     * the set of rows the migration declined to decide for, and the operator's fix is to
     * name the real host and re-enter the token.
     */
    public function allowedHost(): ?string
    {
        $host = $this->host !== null ? trim($this->host) : '';

        return $host !== '' ? strtolower($host) : null;
    }

    /**
     * Whether this credential may be used against the given repository URL. Fails closed:
     * an unparseable URL or a credential without a known host is never authenticated.
     *
     * The comparison is on the whole authority, port included. Comparing the hostname alone
     * left the binding open on exactly the axis it exists to close: `GitAuth::origin()` scopes
     * the Authorization header to scheme://host:port, so a maintainer who set a package's
     * repository_url to `https://gitlab.corp:9999/x` had their organization's PAT delivered to
     * whatever listens on that port — a different service on the same self-hosted machine, or
     * one they run themselves. "Where does this token get sent" must not be nominatable by the
     * party the binding is meant to constrain.
     */
    public function permits(?string $url): bool
    {
        $allowed = $this->allowedHost();
        if ($allowed === null || $url === null) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return false;
        }

        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';

        // Shared with the inline `packages.repository_token` guard, so the two credential
        // columns cannot answer "where does this token get sent" differently again.
        return RepositoryAuthority::normalize($parts['host'], isset($parts['port']) ? (int) $parts['port'] : null, $scheme)
            === RepositoryAuthority::normalize($allowed, null, $scheme);
    }

    /** Operator-facing explanation for a refused host, shared by every entry point. */
    public function hostMismatchMessage(): string
    {
        $allowed = $this->allowedHost();

        return $allowed === null
            ? 'Für dieses Git-Token ist kein Host hinterlegt — bitte den Host am Token ergänzen.'
            : "Dieses Git-Token ist an {$allowed} gebunden (Port inklusive) und darf an keine andere Adresse gesendet werden. "
                .'Gehört das Repository dorthin, tragen Sie den Host — bei Bedarf mit Port, z. B. gitlab.example:8443 — am Token ein und geben Sie das Token neu ein.';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}

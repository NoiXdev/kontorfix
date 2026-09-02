<?php

namespace App\Models;

use App\Enums\GitProvider;
use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Support\AbandonmentNotice;
use App\Support\CredentialUrl;
use App\Support\RepositoryAuthority;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, HasUuids, LogsActivity {
        // Aliased so the override below can call through: LogsActivity is a trait, so a
        // method of the same name on the class replaces it outright and `parent::` reaches
        // Model, which has none.
        LogsActivity::buildChanges as protected spatieBuildChanges;
    }

    /**
     * Keeps `repository_token_host` truthful without any write path having to remember it.
     *
     * The binding is stamped exactly when the token itself is written, never when the URL
     * moves: re-deriving it from a changed URL would rebind the secret to whatever the
     * caller just typed, which is the retarget this exists to refuse. Entering a token is
     * the act that names its destination.
     */
    protected static function booted(): void
    {
        static::saving(function (self $package): void {
            if (! $package->isDirty('repository_token')) {
                return;
            }

            $package->repository_token_host = $package->repository_token === null
                ? null
                : RepositoryAuthority::of($package->repository_url);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('package')
            ->logOnly(['name', 'type', 'repository_url', 'sync_status', 'abandoned_at', 'replacement_package', 'abandonment_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Keeps a git PAT out of `activity_log.properties`.
     *
     * `repository_url` legitimately carries `https://x-access-token:<PAT>@github.com/…` —
     * that is the supported inline shape and CredentialUrl exists because the column cannot
     * simply reject it. Logging the raw value put the secret in a second table, in
     * cleartext, where it outlives the rotation that answers a leak and is rendered to any
     * reader of the activity view. Redacted rather than dropped: which host a repository
     * moved to is the audit-relevant fact, and a bare boolean would lose it.
     *
     * The dirty comparison in the parent runs on the real values, so what is logged is
     * still exactly what changed.
     *
     * @return array<string, mixed>
     */
    protected function buildChanges(string $processingEvent): array
    {
        $changes = $this->spatieBuildChanges($processingEvent);

        foreach (['attributes', 'old'] as $bag) {
            if (is_array($changes[$bag] ?? null) && array_key_exists('repository_url', $changes[$bag])) {
                $changes[$bag]['repository_url'] = CredentialUrl::redact($changes[$bag]['repository_url']);
            }
        }

        return $changes;
    }

    protected $fillable = [
        'organization_id',
        'type',
        'source_mode',
        'name',
        'description',
        'readme_html',
        'repository_url',
        'repository_token',
        'git_credential_id',
        'sync_status',
        'sync_error',
        'synced_at',
        'dist_tags',
        'abandoned_at',
        'replacement_package',
        'abandonment_reason',
    ];

    /**
     * Never serialise the git access token — it must not reach the frontend or API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'repository_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'source_mode' => PackageSourceMode::class,
            'sync_status' => SyncStatus::class,
            'synced_at' => 'datetime',
            'dist_tags' => 'array',
            'abandoned_at' => 'datetime',
            // Encrypted at rest; decrypted transparently when building git auth.
            'repository_token' => 'encrypted',
        ];
    }

    /**
     * Whether this package is populated by mirroring a git repository's tags.
     *
     * The stored column is the answer, with no per-type special case on top: every writer
     * resolves the mode through `PackageSourceMode::allowedFor()` before saving, so a
     * Composer row always carries `git` — that rule lives on the enum and only there.
     */
    public function isGitSourced(): bool
    {
        return $this->source_mode === PackageSourceMode::Git;
    }

    /** Whether this package is populated by pushing artifacts (npm publish / twine upload). */
    public function isPublishSourced(): bool
    {
        return ! $this->isGitSourced();
    }

    /** Whether an operator has retired this package. */
    public function isAbandoned(): bool
    {
        return $this->abandoned_at !== null;
    }

    /**
     * The wording for this package's abandonment, or null while it is live.
     *
     * Returning null rather than an empty notice is what keeps the metadata builders from
     * emitting the key at all on a live package — a builder that always writes `abandoned`
     * or `deprecated` would mark the entire registry as retired.
     */
    public function abandonmentNotice(): ?AbandonmentNotice
    {
        if (! $this->isAbandoned()) {
            return null;
        }

        return new AbandonmentNotice($this->replacement_package, $this->abandonment_reason);
    }

    /**
     * @return HasMany<PackageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class)->orderByDesc('released_at');
    }

    /**
     * Python distribution files (sdists/wheels) served over the PEP 503 simple API.
     * Only ever populated for PackageType::Python.
     *
     * @return HasMany<PythonDist, $this>
     */
    public function pythonDists(): HasMany
    {
        return $this->hasMany(PythonDist::class);
    }

    /**
     * The organization that owns this package. A package may only be attached to
     * registries of this organization — see GuardsPackageAttachment.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Group, $this, GroupPackage>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->using(GroupPackage::class)
            ->withPivot('version_constraint', 'available_until');
    }

    /**
     * @return BelongsTo<GitCredential, $this>
     */
    public function gitCredential(): BelongsTo
    {
        return $this->belongsTo(GitCredential::class);
    }

    /**
     * Effective git authentication for syncing this package's repository. Prefers an
     * assigned managed credential (with its provider), else the inline per-package token
     * (treated as a GitHub token).
     *
     * @return array{token: ?string, provider: GitProvider, username: ?string}
     */
    public function gitAuth(): array
    {
        $credential = $this->gitCredential;
        if ($credential !== null) {
            // Last line of defence: a credential is bound to one host, so a repository URL
            // that no longer matches gets no token rather than leaking it to that host.
            if (! $credential->permits($this->repository_url)) {
                return ['token' => null, 'provider' => $credential->provider, 'username' => $credential->username];
            }

            $credential->forceFill(['last_used_at' => now()])->saveQuietly();

            return ['token' => $credential->token, 'provider' => $credential->provider, 'username' => $credential->username];
        }

        // The inline column gets the check the managed one already has. `repository_token_host`
        // records the authority the token was entered for, so a repository_url that has since
        // moved — to another port of the same host, which the old console guard could not
        // see, or by any writer that does not pass through the console at all — gets no token
        // rather than shipping the organization's PAT to an address someone else named.
        // Fails closed on a null binding: a token whose destination is unknown is not sent.
        if ($this->repository_token !== null
            && RepositoryAuthority::of($this->repository_url) !== $this->repository_token_host) {
            return ['token' => null, 'provider' => GitProvider::GitHub, 'username' => null];
        }

        return ['token' => $this->repository_token, 'provider' => GitProvider::GitHub, 'username' => null];
    }
}

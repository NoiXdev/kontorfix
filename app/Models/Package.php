<?php

namespace App\Models;

use App\Enums\GitProvider;
use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
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
    use HasFactory, HasUuids, LogsActivity;

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
            ->logOnly(['name', 'type', 'repository_url', 'sync_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'type',
        'source_mode',
        'name',
        'description',
        'repository_url',
        'repository_token',
        'git_credential_id',
        'sync_status',
        'sync_error',
        'synced_at',
        'dist_tags',
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
            // Encrypted at rest; decrypted transparently when building git auth.
            'repository_token' => 'encrypted',
        ];
    }

    /**
     * Whether this package is populated by mirroring a git repository's tags. Composer is
     * always git-sourced; npm/Python are git-sourced only when explicitly configured.
     */
    public function isGitSourced(): bool
    {
        return $this->type === PackageType::Composer || $this->source_mode === PackageSourceMode::Git;
    }

    /** Whether this package is populated by pushing artifacts (npm publish / twine upload). */
    public function isPublishSourced(): bool
    {
        return ! $this->isGitSourced();
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

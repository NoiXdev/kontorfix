<?php

namespace App\Models;

use App\Enums\GitProvider;
use Database\Factories\GitCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    /** @var list<string> */
    protected $hidden = [
        'token',
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
        ];
    }

    /**
     * The single host this credential's token may be transmitted to: the explicitly
     * stored one, else the provider's canonical host. Null means "unknown" — the
     * credential is then unusable rather than usable everywhere.
     */
    public function allowedHost(): ?string
    {
        $host = $this->host !== null ? trim($this->host) : '';

        return $host !== '' ? strtolower($host) : $this->provider->defaultHost();
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

        return self::authority($parts['host'], isset($parts['port']) ? (int) $parts['port'] : null, $scheme)
            === self::authority($allowed, null, $scheme);
    }

    /**
     * `host[:port]`, with the scheme's default port dropped so that `gitlab.corp` and
     * `https://gitlab.corp:443/...` agree while `https://gitlab.corp:9999/...` does not.
     * A port embedded in the stored host is parsed out of it the same way.
     */
    private static function authority(string $host, ?int $port, string $scheme): string
    {
        $host = strtolower(trim($host));

        if ($port === null && str_contains($host, ':')) {
            [$host, $rawPort] = explode(':', $host, 2);
            $port = ctype_digit($rawPort) ? (int) $rawPort : null;
        }

        $default = match (strtolower($scheme)) {
            'http' => 80,
            'ssh', 'git+ssh' => 22,
            default => 443,
        };

        return $port === null || $port === $default ? $host : $host.':'.$port;
    }

    /** Operator-facing explanation for a refused host, shared by every entry point. */
    public function hostMismatchMessage(): string
    {
        $allowed = $this->allowedHost();

        return $allowed === null
            ? 'Für dieses Git-Token ist kein Host hinterlegt — bitte den Host am Token ergänzen.'
            : "Dieses Git-Token ist an {$allowed} gebunden (Port inklusive) und darf an keine andere Adresse gesendet werden. "
                .'Gehört das Repository dorthin, trage den Host — bei Bedarf mit Port, z. B. gitlab.example:8443 — am Token ein und gib das Token neu ein.';
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

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
     */
    public function permits(?string $url): bool
    {
        $allowed = $this->allowedHost();
        if ($allowed === null || $url === null) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === $allowed;
    }

    /** Operator-facing explanation for a refused host, shared by every entry point. */
    public function hostMismatchMessage(): string
    {
        $allowed = $this->allowedHost();

        return $allowed === null
            ? 'Für dieses Git-Token ist kein Host hinterlegt — bitte den Host am Token ergänzen.'
            : "Dieses Git-Token ist an {$allowed} gebunden und darf an keinen anderen Host gesendet werden.";
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

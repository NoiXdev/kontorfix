<?php

namespace App\Models;

use App\Enums\TokenAbility;
use Database\Factories\RegistryTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $user_id
 * @property string|null $plain_text Only set directly after issue(), never persisted.
 */
class RegistryToken extends Model
{
    /** @use HasFactory<RegistryTokenFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'group_id',
        'name',
        'token_hash',
        'ability',
        'last_used_at',
        'expires_at',
    ];

    /**
     * The token is stored only as a sha256 hash (never reversible), but keep even that out
     * of any serialised payload.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'ability' => TokenAbility::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Tokens that have not been revoked. Revoked rows are kept — a lifecycle action must
     * be inspectable and undoable — but they are dead for every purpose: resolution,
     * every listing and the dashboard count.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotRevoked(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: self, 1: string}
     */
    public static function issue(Organization $org, string $name, ?Group $group, TokenAbility $ability = TokenAbility::Read, ?\DateTimeInterface $expiresAt = null, ?User $owner = null): array
    {
        $plain = 'kfx_'.Str::random(40);
        $token = static::create([
            'organization_id' => $org->id,
            'user_id' => $owner?->id,
            'group_id' => $group?->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'ability' => $ability,
            'expires_at' => $expiresAt ?? static::defaultExpiry(),
        ]);

        return [$token, $plain];
    }

    /**
     * Instance-wide default lifetime for newly issued tokens when the caller does not
     * pass one. Null — the shipped default — keeps tokens open-ended, so upgrading never
     * expires a credential that is in use today. An operator who wants a rotation policy
     * sets KONTORFIX_REGISTRY_TOKEN_TTL_DAYS; that applies to tokens issued from then on
     * and never retroactively.
     */
    public static function defaultExpiry(): ?Carbon
    {
        $days = (int) config('kontorfix.registry_token_ttl_days', 0);

        return $days > 0 ? now()->addDays($days) : null;
    }

    public static function findByPlainText(string $plain): ?self
    {
        $token = static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        return $token?->ownerIsStillEntitled() ? $token : null;
    }

    /**
     * Whether the account behind a personal token still holds the access the token was
     * issued under. This is checked at resolution time (not only when a membership is
     * removed) so that no lifecycle path — admin console, API, a direct pivot write or a
     * future one — can leave a live credential behind for someone who has left.
     *
     * The rule:
     *  - `user_id === null` is an organization-owned token (admin console / API). Its
     *    lifecycle is the organization's, not a person's, so it is unaffected.
     *  - a personal token dies with the owner's membership of the token's organization
     *    (home organization or an additional membership) — a token for an organization
     *    someone has left cannot be legitimate.
     *  - a personal *publish* token additionally requires the owner to still administer
     *    that organization, mirroring RegistryTokenPolicy::create: a token must never
     *    grant more than its owner could obtain right now, so a maintainer demoted to
     *    member cannot keep writing. A read token survives a demotion, because reading
     *    is self-service for every member.
     *
     * This is entitlement only, and it is derived — it follows the account's current
     * state in both directions, so a demotion makes a publish token inert immediately and
     * undoing the demotion makes it work again. An explicit `revoked_at` (set by
     * RegistryTokenLifecycleService on a real deprovisioning) is the separate, sticky
     * decision and is applied by findByPlainText(). A deleted account is handled at the
     * source — User::deleting removes the rows, since the FK is `nullOnDelete` and would
     * otherwise silently promote a personal token into an ownerless organization token.
     */
    public function ownerIsStillEntitled(): bool
    {
        if ($this->user_id === null) {
            return true;
        }

        $owner = $this->relationLoaded('user') ? $this->user : User::find($this->user_id);

        if ($owner === null || ! $owner->belongsToOrganization($this->organization_id)) {
            return false;
        }

        return $this->ability !== TokenAbility::Publish || $owner->administers($this->organization_id);
    }
}

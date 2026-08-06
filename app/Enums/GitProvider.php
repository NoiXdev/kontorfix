<?php

namespace App\Enums;

/**
 * Git hosting providers a stored credential can target. Each maps to the HTTP Basic
 * username convention that host expects (the token itself is always the password).
 * Single source of truth for provider behaviour + the frontend picker.
 */
enum GitProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Generic => 'Generisch (HTTPS)',
        };
    }

    /**
     * The Basic-auth username to pair with the token. Hosts authenticate on the token
     * (password); the username is a fixed convention. A stored credential may override it.
     */
    public function basicUsername(): string
    {
        return match ($this) {
            self::GitHub => 'x-access-token',
            self::GitLab => 'oauth2',
            self::Bitbucket => 'x-token-auth',
            self::Generic => 'git',
        };
    }

    /**
     * @return list<array{value:string, label:string}>
     */
    public static function metadata(): array
    {
        return array_map(fn (self $p): array => [
            'value' => $p->value,
            'label' => $p->label(),
        ], self::cases());
    }
}

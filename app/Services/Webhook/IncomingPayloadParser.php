<?php

namespace App\Services\Webhook;

use Illuminate\Http\Request;

class IncomingPayloadParser
{
    public function verify(string $provider, Request $request, string $secret): bool
    {
        return match ($provider) {
            'github', 'gitea' => $this->verifyHmacHeader(
                $request,
                $provider === 'github' ? 'X-Hub-Signature-256' : 'X-Gitea-Signature',
                $secret,
                $provider === 'github' ? 'sha256=' : '',
            ),
            'gitlab' => hash_equals($secret, (string) $request->header('X-Gitlab-Token', '')),
            'bitbucket' => hash_equals($secret, (string) $request->query('token', '')),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function repoUrl(string $provider, array $payload): ?string
    {
        return match ($provider) {
            'github', 'gitea' => $payload['repository']['clone_url'] ?? null,
            'gitlab' => $payload['repository']['git_http_url'] ?? $payload['project']['git_http_url'] ?? null,
            'bitbucket' => $payload['repository']['links']['html']['href'] ?? null,
            default => null,
        };
    }

    private function verifyHmacHeader(Request $request, string $header, string $secret, string $prefix): bool
    {
        $provided = (string) $request->header($header, '');

        if ($prefix !== '' && ! str_starts_with($provided, $prefix)) {
            return false;
        }

        $signature = $prefix !== '' ? substr($provided, strlen($prefix)) : $provided;

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}

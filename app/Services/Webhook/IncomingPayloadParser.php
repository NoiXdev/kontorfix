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
            // Bitbucket used to be compared against `?token=`, i.e. a bare bearer token in
            // the request line: disclosed to every access log, CDN and APM on the path, on
            // every delivery, and replayable forever from a single captured URL. Both
            // Bitbucket Cloud and Data Center send `X-Hub-Signature: sha256=<hmac>` when
            // the webhook has a secret, so it gets the same body-bound construction as the
            // github branch and the secret never travels. The query parameter is not
            // accepted as a fallback — that would keep the disclosure — and nothing that
            // worked is lost: the admin UI never emitted a token-bearing URL and the REST
            // reference never described the convention, so a hook configured as documented
            // always 401'd.
            'bitbucket' => $this->verifyHmacHeader($request, 'X-Hub-Signature', $secret, 'sha256='),
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

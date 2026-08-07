<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPackage;
use App\Models\IncomingWebhook;
use App\Models\IncomingWebhookEvent;
use App\Services\Webhook\IncomingPayloadParser;
use App\Services\Webhook\RepoUrlMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IncomingWebhookController extends Controller
{
    private const PROVIDERS = ['github', 'gitlab', 'gitea', 'bitbucket'];

    public function __construct(
        private readonly RepoUrlMatcher $matcher,
        private readonly IncomingPayloadParser $parser,
    ) {}

    /**
     * Handles a webhook sent either to a per-record endpoint (/webhooks/{provider}/{hook})
     * or the legacy shared endpoint (/webhooks/{provider}, using the env secret). Every
     * delivery is logged to the audit trail, whether it verifies or not.
     */
    public function handle(Request $request, string $provider, ?string $hookId = null): JsonResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        // The webhooks route group runs without SubstituteBindings (it is deliberately
        // outside the web/api groups), so the record is resolved by hand here.
        $hook = null;

        // A per-record endpoint must match the record's provider and be enabled.
        if ($hookId !== null) {
            // `incoming_webhooks.id` is a Postgres uuid column, so a non-uuid id makes the
            // driver raise SQLSTATE[22P02] before the 404 below can run — an anonymous,
            // signature-free 500. The id is not a bound route model here (this group runs
            // without SubstituteBindings), so its shape is checked by hand.
            abort_unless(Str::isUuid($hookId), 404);

            $hook = IncomingWebhook::find($hookId);
            abort_unless($hook !== null && $hook->provider === $provider && $hook->enabled, 404);
            $secret = $hook->secret;
        } else {
            $secret = (string) config('kontorfix.incoming_webhook_secret');
            abort_if($secret === '', 503, 'Incoming webhooks are not configured.');
        }

        $payload = $request->json()->all();
        $valid = $this->parser->verify($provider, $request, $secret);
        $repoUrl = $valid ? $this->parser->repoUrl($provider, $payload) : null;

        $matched = 0;
        $status = 200;

        if (! $valid) {
            $status = 401;
        } elseif ($repoUrl === null) {
            $status = 422;
        } else {
            $packages = $this->matcher->match($repoUrl);
            $matched = $packages->count();
            foreach ($packages as $package) {
                SyncPackage::dispatch($package);
            }
        }

        // Record BEFORE returning so failed verifications are debuggable too. The payload
        // is stored as-is for inspection; secrets are never part of a webhook body.
        IncomingWebhookEvent::record([
            'incoming_webhook_id' => $hook?->id,
            'provider' => $provider,
            'repo_url' => $repoUrl,
            'signature_valid' => $valid,
            'matched_packages' => $matched,
            'status_code' => $status,
            'ip' => $request->ip(),
            'payload' => $payload,
        ]);

        if ($hook !== null && $valid) {
            $hook->forceFill(['last_received_at' => now()])->save();
        }

        abort_unless($valid, 401);
        abort_if($repoUrl === null, 422);

        return response()->json(['synced' => $matched]);
    }
}

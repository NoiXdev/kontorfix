<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPackage;
use App\Services\Webhook\IncomingPayloadParser;
use App\Services\Webhook\RepoUrlMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomingWebhookController extends Controller
{
    public function __construct(
        private readonly RepoUrlMatcher $matcher,
        private readonly IncomingPayloadParser $parser,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['github', 'gitlab', 'gitea', 'bitbucket'], true), 404);

        $secret = (string) config('kontorfix.incoming_webhook_secret');
        abort_if($secret === '', 503, 'Incoming webhooks are not configured.');

        abort_unless($this->parser->verify($provider, $request, $secret), 401);

        $repoUrl = $this->parser->repoUrl($provider, $request->json()->all());
        abort_if($repoUrl === null, 422);

        $packages = $this->matcher->match($repoUrl);
        foreach ($packages as $package) {
            SyncPackage::dispatch($package);
        }

        return response()->json(['synced' => $packages->count()]);
    }
}

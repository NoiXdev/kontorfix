<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

class PasskeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/Passkeys', [
            'passkeys' => $request->user()->passkeys()->latest()->get()
                ->map(fn (Passkey $p) => [
                    'id' => (string) $p->id,
                    'name' => $p->name,
                    'authenticator' => $p->authenticator,
                    'last_used_at' => $p->last_used_at?->diffForHumans(),
                    'created_at' => $p->created_at?->toDateString(),
                ]),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMailSettingRequest;
use App\Http\Requests\TestMailRequest;
use App\Models\MailSetting;
use App\Services\Mail\MailManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MailController extends Controller
{
    public function show(): Response
    {
        $s = MailSetting::current();

        return Inertia::render('admin/mail/Index', [
            'settings' => [
                'mailer' => $s->mailer,
                'from_address' => $s->from_address,
                'from_name' => $s->from_name,
                'smtp_host' => $s->smtp_host,
                'smtp_port' => $s->smtp_port,
                'smtp_username' => $s->smtp_username,
                'smtp_encryption' => $s->smtp_encryption,
                'postal_domain' => $s->postal_domain,
                // Never ship the secrets themselves — only whether one is stored.
                'has_smtp_password' => filled($s->smtp_password),
                'has_postal_key' => filled($s->postal_key),
            ],
        ]);
    }

    public function update(UpdateMailSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Empty secret fields mean "keep the stored value" rather than "clear it".
        foreach (['smtp_password', 'postal_key'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        MailSetting::current()->update($data);

        return back()->with('success', 'E-Mail-Einstellungen gespeichert.');
    }

    public function test(TestMailRequest $request, MailManager $manager): JsonResponse
    {
        // Probe the SUBMITTED (not yet saved) config, so an admin can verify
        // credentials before persisting them.
        $setting = $manager->fromInput($request->validated());

        return response()->json($manager->sendTest($setting, (string) $request->validated('recipient')));
    }
}

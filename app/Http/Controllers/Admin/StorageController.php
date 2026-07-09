<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStorageSettingRequest;
use App\Models\StorageSetting;
use App\Services\Storage\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function show(): Response
    {
        $s = StorageSetting::current();

        return Inertia::render('admin/storage/Index', [
            'settings' => [
                'driver' => $s->driver,
                'key' => $s->key,
                'region' => $s->region,
                'bucket' => $s->bucket,
                'endpoint' => $s->endpoint,
                'url' => $s->url,
                'use_path_style' => $s->use_path_style,
                'has_secret' => filled($s->secret),
            ],
        ]);
    }

    public function update(UpdateStorageSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['secret'] ?? null)) {
            unset($data['secret']);
        }

        $data['use_path_style'] = $request->boolean('use_path_style');

        StorageSetting::current()->update($data);

        return back()->with('success', 'Storage-Einstellungen gespeichert.');
    }

    public function test(UpdateStorageSettingRequest $request, StorageManager $manager): JsonResponse
    {
        // Die EINGEREICHTE (noch nicht gespeicherte) Config testen, nicht die alte —
        // ein leeres Secret bedeutet „bestehendes behalten".
        $current = StorageSetting::current();
        $setting = new StorageSetting;
        $setting->fill($request->safe()->except('secret'));
        $setting->use_path_style = $request->boolean('use_path_style');
        $setting->secret = filled($request->validated('secret'))
            ? (string) $request->validated('secret')
            : $current->secret;

        return response()->json($manager->testConfig($manager->diskConfigFor($setting)));
    }
}

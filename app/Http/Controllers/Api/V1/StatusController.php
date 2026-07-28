<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'packages' => Package::count(),
            'sync' => [
                'synced' => Package::where('sync_status', 'synced')->count(),
                'syncing' => Package::where('sync_status', 'syncing')->count(),
                'pending' => Package::where('sync_status', 'pending')->count(),
                'failed' => Package::where('sync_status', 'failed')->count(),
            ],
        ]]);
    }
}

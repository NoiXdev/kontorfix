<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthService;
use Inertia\Inertia;
use Inertia\Response;

class StatusController extends Controller
{
    public function index(HealthService $health): Response
    {
        return Inertia::render('admin/status/Index', ['checks' => $health->checks()]);
    }
}

<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    /**
     * The portal's landing page: the packages the addressed organization can install.
     *
     * A stub for now — the address, the switch and the gate around it are what this
     * change builds, and the list itself arrives with the portal package set.
     */
    public function index(): Response
    {
        return Inertia::render('portal/Packages', [
            'packages' => [],
        ]);
    }
}

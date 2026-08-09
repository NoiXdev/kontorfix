<?php

// `/docs/api` executes third-party JavaScript from unpkg.com on the application origin,
// un-SRI'd, in a session that by definition belongs to an admin of the operator
// organization. Scramble registers the route unconditionally, so declining that
// dependency was impossible. It is now an operator decision — with the previous
// behaviour as the default, because the page is a documented feature.

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

afterEach(function () {
    // Both of these outlive the application instance: `$defaultRoutesIgnored` is a static
    // on the vendor class, and the configuration registry it drives (`expose(false)`) is
    // another. Leaving either set would take the docs routes away from every later test
    // in the process.
    putenv('KONTORFIX_API_DOCS_ENABLED');
    Scramble::$defaultRoutesIgnored = false;
    Scramble::getConfigurationsInstance()->get('default')->expose();
    $this->refreshApplication();
});

it('registers the browser by default', function () {
    expect(Route::has('scramble.docs.ui'))->toBeTrue()
        ->and(Route::has('scramble.docs.document'))->toBeTrue();
});

it('registers neither route when the operator switches the browser off', function () {
    putenv('KONTORFIX_API_DOCS_ENABLED=false');
    $this->refreshApplication();

    expect(Route::has('scramble.docs.ui'))->toBeFalse()
        ->and(Route::has('scramble.docs.document'))->toBeFalse();
});

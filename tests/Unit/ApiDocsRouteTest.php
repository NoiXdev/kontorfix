<?php

// `/docs/api` executes third-party JavaScript from unpkg.com on the application origin,
// un-SRI'd, in a session that by definition belongs to an admin of the operator
// organization. Scramble registers the route unconditionally, so declining that
// dependency was impossible. It is now an operator decision — with the previous
// behaviour as the default, because the page is a documented feature.

use Illuminate\Support\Facades\Route;

afterEach(function () {
    putenv('KONTORFIX_API_DOCS_ENABLED');
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

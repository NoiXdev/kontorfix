<?php

use App\Support\DigestLine;

// `DigestLine` used to be declared inside DigestSummary.php alongside `DigestSummary`.
// PSR-4 only maps `App\Support\DigestSummary` to that file, so `DigestLine` was reachable
// only as a side effect of `DigestSummary` being autoloaded first — it had no mapping of
// its own. This asserts the class autoloads independently, which fails against the
// two-classes-in-one-file layout.
it('autoloads DigestLine on its own, independent of DigestSummary', function () {
    expect(class_exists(DigestLine::class, true))->toBeTrue();
});

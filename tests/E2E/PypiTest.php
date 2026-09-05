<?php

use Tests\E2E\Support\E2eStack;

it('uploads a freshly built wheel with the real twine client', function () {
    $context = E2eStack::context();

    // The wheel is built here rather than committed: no binary belongs in the repository,
    // and a stale committed artifact would silently stop matching the source next to it.
    $script = <<<SH
        set -e
        rm -rf /work/pkg && mkdir -p /work/pkg && cp -R /fixtures/python-package/. /work/pkg/
        cd /work/pkg
        python -m build --wheel
        twine upload --repository-url {$context['base_url']}/ \
            -u x -p {$context['publish_token']} \
            --disable-progress-bar dist/*.whl
        SH;

    $process = E2eStack::exec('client-python', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // The registry must now list the file the client uploaded.
    $simple = E2eStack::get('/simple/'.$context['python_package'], $context['read_token']);

    expect($simple['status'])->toBe(200)
        ->and($simple['body'])->toContain('kontorfix_e2e_demo-1.0.0-py3-none-any.whl');
});

it('installs the uploaded wheel with the real pip client', function () {
    $context = E2eStack::context();

    // --index-url REPLACES PyPI rather than adding to it, so a resolution that reached the
    // public index would be a failure here, not a fallback. --trusted-host is required
    // because the stack speaks plain HTTP.
    $script = <<<SH
        set -e
        rm -rf /work/venv && python -m venv /work/venv
        /work/venv/bin/pip install --no-cache-dir --quiet \
            --index-url http://x:{$context['publish_token']}@app:8080/r/e2e-customer/e2e-registry/simple \
            --trusted-host app \
            {$context['python_package']}=={$context['version']}
        /work/venv/bin/python -c "import {$context['python_module']}; print({$context['python_module']}.__version__)"
        SH;

    $process = E2eStack::exec('client-python', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe($context['version']);
});

it('refuses an anonymous pip index read with 401 and installs nothing', function () {
    $context = E2eStack::context();

    // Half one: the server said 401. A failing client command alone proves nothing — a typo
    // in the URL fails identically.
    $anonymous = E2eStack::get('/simple/'.$context['python_package']);

    expect($anonymous['status'])->toBe(401);

    // Half two: nothing landed on disk. A fresh venv and a fresh pip cache directory make
    // sure no credential or cached response from the earlier authenticated install can leak
    // into this run and rescue it for the wrong reason.
    // pip's own output is discarded: on a 401 with no TTY, pip tries to prompt for a
    // username, writes "User for app:8080: " to stdout with no trailing newline, then
    // hits EOFError — which would otherwise glue straight onto the marker line below and
    // break the exact-match on it. Nothing about what the test proves depends on pip's
    // chatter, only on whether the import worked afterwards.
    $script = <<<SH
        rm -rf /work/anonvenv /work/anoncache && python -m venv /work/anonvenv
        PIP_CACHE_DIR=/work/anoncache /work/anonvenv/bin/pip install --no-cache-dir --quiet \
            --index-url http://app:8080/r/e2e-customer/e2e-registry/simple \
            --trusted-host app \
            {$context['python_package']} >/dev/null 2>&1 || true
        /work/anonvenv/bin/python -c "import {$context['python_module']}" 2>/dev/null && echo IMPORTED || echo ABSENT
        SH;

    $process = E2eStack::exec('client-python', $script, 600);

    // Equality on the final line, not a suffix match on the whole output: `toEndWith`
    // reads the wrong shape here too, for the same reason NpmTest.php's sibling test spells
    // out — it happens to be safe today only because "IMPORTED" cannot end with "ABSENT".
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $process->getOutput())),
        fn (string $line): bool => $line !== '',
    ));

    expect(end($lines))->toBe('ABSENT');
});

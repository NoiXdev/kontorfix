<?php

use Symfony\Component\Process\Process;

function e2eCompose(array $args): Process
{
    $process = new Process(
        array_merge(['docker', 'compose', '-f', 'docker/compose.e2e.yaml'], $args),
        dirname(__DIR__, 2),
        null,
        null,
        180.0,
    );
    $process->run();

    return $process;
}

it('answers the health endpoint on the published port', function () {
    $body = @file_get_contents('http://127.0.0.1:8099/up');

    expect($body)->not->toBeFalse()
        ->and(json_decode((string) $body, true))->toBe(['status' => 'ok']);
});

it('serves the composer fixture repository over git://, tag included', function () {
    $process = e2eCompose([
        'exec', '-T', 'client-composer',
        'git', 'ls-remote', '--tags', 'git://gitserver/demo.git',
    ]);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('refs/tags/v1.0.0');
});

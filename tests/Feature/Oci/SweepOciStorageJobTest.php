<?php

// The job behind the admin page's "Jetzt bereinigen" button deletes blobs, manifests and
// upload sessions. Until now only its DISPATCH was tested (OciSweeperPageTest asserts
// Queue::assertPushed) — handle() itself never ran in the suite, so nothing proved the one
// thing the job contributes over calling the sweeper directly: that it forwards the
// CONFIGURED blob budget. A wrong config key or a changed default would sweep a different
// amount than the operator asked for, silently, and OciSweeper's own tests would all still
// pass, because they are handed the limit explicitly.

use App\Jobs\SweepOciStorage;
use App\Services\Oci\Sweeper\OciSweeper;
use App\Support\Oci\SweepReport;
use Mockery\MockInterface;

it('forwards the configured blob budget to the sweeper', function () {
    config()->set('kontorfix.oci_sweep_blob_limit', 250);

    /** @var OciSweeper&MockInterface $sweeper */
    $sweeper = Mockery::mock(OciSweeper::class);
    $sweeper->shouldReceive('sweep')->once()->with(250)->andReturn(new SweepReport(0, 0, 0, 0, 0, 0, 0));

    (new SweepOciStorage)->handle($sweeper);
});

it('sweeps nothing when the budget is configured as zero — a trap worth knowing about', function () {
    // Pinned as CURRENT behaviour, not as desired behaviour. config/kontorfix.php reads
    // `(int) env('KONTORFIX_OCI_SWEEP_BLOB_LIMIT', 1000)`, so an env var that is present
    // but EMPTY casts to 0 — and the `1000` fallback inside the job is dead code, because
    // the config key always exists. The result is a scheduled sweep that quietly removes
    // nothing while storage grows, with no error anywhere to notice it.
    config()->set('kontorfix.oci_sweep_blob_limit', 0);

    /** @var OciSweeper&MockInterface $sweeper */
    $sweeper = Mockery::mock(OciSweeper::class);
    $sweeper->shouldReceive('sweep')->once()->with(0)->andReturn(new SweepReport(0, 0, 0, 0, 0, 0, 0));

    (new SweepOciStorage)->handle($sweeper);
});

it('declares its own timeout rather than inheriting the supervisor default', function () {
    // config/horizon.php raises the supervisor timeout to SyncPackage's 900s, and a job
    // that declares none inherits it. A sweep hanging on the disk should be killed at ten
    // minutes; the next run resumes where the budget left off, because every delete is
    // idempotent and the remaining count carries over by construction.
    expect((new SweepOciStorage)->timeout)->toBe(600);
});

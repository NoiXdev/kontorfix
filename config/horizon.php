<?php

use App\Jobs\ScanOciArtifact;
use App\Jobs\SyncPackage;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        // Deliberately far more slack than `default`: one ScanOciArtifact legitimately
        // occupies its worker for the whole poll budget (kontorfix.scanner.timeout), so a
        // short threshold here would alert on a scanner that is merely slow rather than on
        // a queue that is actually backing up.
        'redis:'.ScanOciArtifact::QUEUE => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            // This value drives THREE kill paths, and only the first of them defers to a
            // job's own $timeout:
            //
            // 1. The worker's `pcntl_alarm`. Illuminate\Queue\Worker::timeoutForJob()
            //    prefers the job's property here, so App\Jobs\SyncPackage gets its own
            //    900s regardless of what this says.
            // 2. Laravel\Horizon\ProcessPool::stopTerminatingProcessesThatAreHanging(),
            //    which hard-stops a worker this many seconds after SIGTERM. It reads
            //    `$this->options->timeout` — this value — and never looks at the job. With
            //    60s here, every autoscaler scale-down (routine: `packages:resync` runs
            //    hourly, the pool grows, then shrinks by slicing the OLDEST processes) and
            //    every `horizon:terminate` SIGKILLed a running `git clone --mirror` about
            //    70 seconds in.
            // 3. Laravel\Horizon\MasterSupervisor::terminate(), which waits at most
            //    `longestActiveTimeout()` — the largest supervisor timeout — before
            //    exit()ing.
            //
            // So this is raised to match the longest job in the application rather than
            // left at the framework default. An earlier version of this comment claimed
            // raising it "is not a substitute" for SyncPackage's property; that was wrong
            // for paths 2 and 3, which is exactly where the mid-clone kills came from.
            //
            // Read from the class so the two files cannot drift apart. The cost of the
            // larger value is that a job which declares no $timeout would get 900s of
            // worker alarm; App\Jobs\DeliverWebhook and App\Jobs\SendNotificationDigest
            // therefore declare 60s explicitly, and any new job should too.
            'timeout' => SyncPackage::TIMEOUT,
            'nice' => 0,
        ],

        /*
         * App\Jobs\ScanOciArtifact alone, on a queue of its own.
         *
         * That job blocks its worker inside ScanRunner::poll() for up to
         * kontorfix.scanner.timeout waiting on an adapter that accepted the scan and never
         * answers — a real and observed scanner failure mode. Left on `default`, a nightly
         * rescan of 200 manifests would hold all ten production processes for hours and
         * stop every other job on the instance (SyncPackage, DeliverWebhook,
         * SendNotificationDigest, SyncMirrorPackage, SweepOciStorage). Its own supervisor
         * with a small pool bounds a scanner outage to the scans themselves.
         *
         * Listed in `defaults` and therefore provisioned in EVERY environment —
         * Laravel\Horizon\ProvisioningPlan::applyDefaultOptions() array_replace_recursive()s
         * these into each entry of `environments`, so a local or e2e `php artisan horizon`
         * picks the queue up without a per-environment entry. Production raises only the
         * process count below.
         *
         * `maxProcesses` is small on purpose: scans are the one workload here whose latency
         * nobody is waiting on, and a bigger pool would only mean more workers parked on a
         * dead adapter.
         *
         * The timeout mirrors App\Jobs\ScanOciArtifact's own `$uniqueFor` margin over the
         * poll budget, so the supervisor-level kill paths (ProcessPool's hang-stop and the
         * master supervisor's terminate wait — see supervisor-1's own note) outlast the job
         * they are supervising instead of cutting a legitimately slow scan short. Read from
         * env directly because a config file cannot read another config file.
         */
        'supervisor-scans' => [
            'connection' => 'redis',
            'queue' => [ScanOciArtifact::QUEUE],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => (int) (env('KONTORFIX_SCANNER_TIMEOUT') ?: 600) + 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            // Two, not ten: a scanner that accepts scans and never reports parks a worker
            // for the whole poll budget, and the cost of that outage has to stay bounded.
            'supervisor-scans' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];

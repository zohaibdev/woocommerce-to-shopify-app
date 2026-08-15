<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Must stay comfortably above ProcessBatchJob's time budget. At the old
            // 90s a long batch was declared dead mid-run and a SECOND copy started,
            // which replayed the page and double-counted every item.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 900),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // 900 for exactly the same reason the database connection uses it,
            // and this default is the one that matters most: moving to Redis is
            // something you do to go FASTER, and Laravel's stock 90 here would
            // have silently reintroduced the worst bug this engine ever had —
            // the queue declaring a running ProcessBatchJob dead mid-batch and
            // starting a second copy of it on the same page. Every job's own
            // timeout must stay below this.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 900),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-starting worker
    |--------------------------------------------------------------------------
    |
    | When true, ordinary API traffic starts a detached `queue:work` if there is
    | queued work and nothing is consuming it — see App\Support\QueueKeeper. It
    | is a safety net for hosting with no cron and no supervisor, not a
    | replacement for either: a real worker still gives far better throughput.
    |
    | Turn it OFF wherever you run supervisor or Horizon, so the two do not
    | compete for the same jobs.
    |
    */

    'auto_worker' => (bool) env('QUEUE_AUTO_WORKER', true),

    /*
    | How many self-started workers may run at once.
    |
    | This used to be hard-wired to one, which made the engine's concurrency
    | design decorative: MigrationRunner derives from the shop's real Shopify
    | budget that it may run up to eight batches in parallel and dispatches that
    | many jobs — and a single worker then ran them one after another. On a large
    | catalogue that is the whole difference between an overnight migration and
    | an afternoon one.
    |
    | Two is a deliberately careful default for shared hosting, where PHP
    | processes and memory are metered. Raise it on a VPS — or better, turn
    | auto_worker off entirely and let supervisor run the pool (see
    | deploy/supervisor/store-shift-worker.conf), which restarts a dead worker
    | immediately instead of on the next poll.
    */

    'auto_workers' => (int) env('QUEUE_AUTO_WORKERS', 2),

    /*
    | Failed jobs are re-queued automatically by `migration:watchdog`, up to this
    | many times each sweep. Bounded so a genuinely poisonous job cannot be
    | retried forever — past this it stays failed and is reported in the admin
    | panel, which is where a human should look at it.
    */

    'auto_retry_failed' => (int) env('QUEUE_AUTO_RETRY_FAILED', 25),

];

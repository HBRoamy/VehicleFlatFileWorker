<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vehicle Data Handler configuration
|--------------------------------------------------------------------------
|
| All application-specific settings live here, grouped by concern. Related
| values are kept together (e.g. all polling knobs under `polling`) so a
| single logical unit can be read or overridden as one object.
|
*/

return [

    // EC2 instance id used as the owner in the file lock table. Sourced from
    // the environment so each host identifies itself distinctly.
    'instance_id' => env('INSTANCE_ID', 'local-instance'),

    /*
    | Which concrete implementation backs each abstraction. Local/dev defaults
    | require no AWS account; switch to the cloud drivers in production.
    */
    'drivers' => [
        'queue'   => env('VEHICLE_QUEUE_DRIVER', 'local'),   // local | sqs
        'storage' => env('VEHICLE_STORAGE_DRIVER', 'local'), // local | s3
        'secrets' => env('VEHICLE_SECRETS_DRIVER', 'env'),   // env   | aws
    ],

    /*
    | Poller idle/backoff behaviour. After the queue has been empty for
    | `empty_poll_threshold_seconds`, the poller sleeps for
    | `backoff_sleep_seconds` before resuming interval polling.
    */
    'polling' => [
        'interval_seconds'             => (int) env('VEHICLE_POLL_INTERVAL', 5),
        'empty_poll_threshold_seconds' => (int) env('VEHICLE_EMPTY_POLL_THRESHOLD', 60),
        'backoff_sleep_seconds'        => (int) env('VEHICLE_BACKOFF_SLEEP', 300),
    ],

    /*
    | Record processing. `max_degree_of_parallelism` is both the worker-pool
    | concurrency cap AND the transactional batch size: N vehicles are
    | validated in parallel and committed in one transaction. Processing is
    | always performed by the amphp/parallel worker pool.
    */
    'processing' => [
        'max_degree_of_parallelism' => (int) env('VEHICLE_MAX_PARALLELISM', 10),
    ],

    /*
    | Secrets + credential rotation. `enable_refresh` toggles the periodic
    | refresh loop; disable it in tests to run a single refresh and exit.
    */
    'secrets' => [
        'enable_refresh'        => (bool) env('VEHICLE_ENABLE_REFRESH', true),
        'refresh_interval_hours'=> (int) env('VEHICLE_REFRESH_INTERVAL_HOURS', 24),
        'aws_secret_id'         => env('VEHICLE_AWS_SECRET_ID', 'vehicle-data-handler'),

        // Local secret map used by the env-backed provider.
        'local' => [
            'sqs_queue_url'        => env('VEHICLE_LOCAL_SQS_URL'),
            's3_bucket'            => env('VEHICLE_LOCAL_BUCKET', 'local-bucket'),
            'db_connection_string' => env('VEHICLE_LOCAL_DB_DSN'),
        ],
    ],

    /*
    | File-lock behaviour. A lock still in `processing` past this timeout is
    | considered stale (owner presumed dead) and may be reclaimed.
    */
    'locking' => [
        'stale_lock_timeout_minutes' => (int) env('VEHICLE_STALE_LOCK_TIMEOUT', 30),
    ],

    /*
    | Storage locations for the local drivers, and AWS region for cloud ones.
    */
    'storage' => [
        'local_incoming_path' => env('VEHICLE_LOCAL_PATH', storage_path('app/incoming')),
        'local_queue_path'    => env('VEHICLE_LOCAL_QUEUE_PATH', storage_path('app/queue')),
    ],

    'aws' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];

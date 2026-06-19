<?php

declare(strict_types=1);

return [
    'database_reachable' => 'Database connection [:connection] is reachable.',
    'database_unreachable' => 'Database connection [:connection] is unreachable.',

    'cache_ok' => 'Cache store [:store] is responding.',
    'cache_unreachable' => 'Cache store [:store] is unreachable.',
    'cache_mismatch' => 'Cache store [:store] returned an unexpected value.',

    'queue_ok' => 'Queue [:connection] is reachable (:size pending).',
    'queue_unreachable' => 'Queue [:connection] is unreachable.',
    'queue_backlog' => 'Queue [:connection] backlog is high (:size pending).',

    'storage_ok' => 'Disk [:disk] has healthy free space.',
    'storage_warning' => 'Disk [:disk] is running low on free space.',
    'storage_low' => 'Disk [:disk] is below the minimum free space.',
    'storage_unreadable' => 'Disk [:disk] free space could not be read.',

    'http_ok' => 'Endpoint [:url] responded successfully.',
    'http_unreachable' => 'Endpoint [:url] could not be reached.',
    'http_bad_status' => 'Endpoint [:url] returned status :status.',
    'http_slow' => 'Endpoint [:url] responded slowly (:latency ms).',
];

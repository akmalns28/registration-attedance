<?php

return [
    'connection' => env('EVENT_NOTIFICATION_CONNECTION'),
    'queue' => env('EVENT_NOTIFICATION_QUEUE', 'emails'),
    'tries' => (int) env('EVENT_NOTIFICATION_TRIES', 3),
    'timeout' => (int) env('EVENT_NOTIFICATION_TIMEOUT', 60),
    'backoff' => [60, 300, 900],
    'stale_after' => (int) env('EVENT_NOTIFICATION_STALE_AFTER', 300),
    'processing_lease' => (int) env('EVENT_NOTIFICATION_PROCESSING_LEASE', 180),
    'recovery_limit' => 100,
    'batch_size' => 100,
];

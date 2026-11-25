<?php

return [
    'memory_limit_mb' => (int) env('INVITATION_EXPORT_MEMORY_LIMIT_MB', 128),
    'direct_limit' => (int) env('INVITATION_EXPORT_DIRECT_LIMIT', 25),
    'batch_size' => (int) env('INVITATION_EXPORT_BATCH_SIZE', 5),
    'retention_hours' => (int) env('INVITATION_EXPORT_RETENTION_HOURS', 24),
    'job_timeout' => (int) env('INVITATION_EXPORT_JOB_TIMEOUT', 120),
];

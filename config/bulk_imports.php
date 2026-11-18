<?php

return [
    'max_upload_kb' => (int) env('BULK_IMPORT_MAX_UPLOAD_KB', 5120),
    'max_rows' => (int) env('BULK_IMPORT_MAX_ROWS', 2000),
    'max_uncompressed_bytes' => 32 * 1024 * 1024,
    'ttl_minutes' => (int) env('BULK_IMPORT_TTL_MINUTES', 60),
];

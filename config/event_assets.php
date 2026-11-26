<?php

return [
    'poster_max_count' => min(29, max(1, (int) env('EVENT_POSTER_MAX_COUNT', 10))),
    'poster_max_kb' => min(30720, max(1, (int) env('EVENT_POSTER_MAX_KB', 30720))),
    'post_max_bytes' => 64 * 1024 * 1024,
    'poster_max_side' => 10000,
    'poster_max_pixels' => 36000000,
    'poster_sizes' => ['thumbnail' => 400, 'detail' => 1200, 'invitation' => 960],
    'gallery_max_files' => (int) env('EVENT_GALLERY_MAX_FILES', 10),
    'gallery_image_max_kb' => (int) env('EVENT_GALLERY_IMAGE_MAX_KB', 8192),
    'gallery_video_max_kb' => min(30720, max(1, (int) env('EVENT_GALLERY_VIDEO_MAX_KB', 30720))),
    'gallery_per_page' => 12,
];

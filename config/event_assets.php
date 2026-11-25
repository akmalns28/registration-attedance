<?php

return [
    'poster_max_count' => min(100, max(1, (int) env('EVENT_POSTER_MAX_COUNT', 10))),
    'poster_max_kb' => (int) env('EVENT_POSTER_MAX_KB', 5120),
    'gallery_max_files' => (int) env('EVENT_GALLERY_MAX_FILES', 10),
    'gallery_image_max_kb' => (int) env('EVENT_GALLERY_IMAGE_MAX_KB', 8192),
    'gallery_video_max_kb' => (int) env('EVENT_GALLERY_VIDEO_MAX_KB', 51200),
    'gallery_per_page' => 12,
];

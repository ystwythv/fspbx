<?php

return [
    // Maximum span of a single CDR API window query, in seconds.
    'api_max_window_seconds' => env('CDR_API_MAX_WINDOW_SECONDS', 30 * 86400),

    // Oldest date_from the API will serve, in seconds before "now".
    'api_max_age_seconds' => env('CDR_API_MAX_AGE_SECONDS', 90 * 86400),

    // Signed recording URL TTL (seconds).
    'recording_url_ttl' => env('CDR_RECORDING_URL_TTL', 1800),

    // Maximum rows written to a single CSV export before truncation.
    'csv_max_rows' => env('CDR_CSV_MAX_ROWS', 250000),
];

<?php

return [

    'stock' => [
        // A supplier's stock list older than this no longer counts (decision 28 Oct 2026).
        'max_age_days' => (int) env('STOCK_MAX_AGE_DAYS', 5),

        // Highest quantity at a single supplier: more than this = "ruim op voorraad",
        // 1 up to this = "beperkt", 0 = "op nalevering". Never summed across suppliers.
        'ample_above' => (int) env('STOCK_AMPLE_ABOVE', 5),

        // A new stock list with fewer rows than this share of the previous one is rejected,
        // so a truncated or empty file never wipes out the stock.
        'min_rows_ratio' => (float) env('STOCK_MIN_ROWS_RATIO', 0.5),
    ],

    'imports' => [
        // Downloaded supplier files are kept this many days for troubleshooting.
        'keep_files_days' => (int) env('IMPORT_KEEP_FILES_DAYS', 7),
    ],

];

<?php

return [
    'currency' => [
        'code' => env('ERP_CURRENCY', 'SAR'),
        // Number of minor-unit decimals (2 for SAR/AED/EGP/USD, 3 for KWD/JOD/BHD/OMR).
        'decimals' => (int) env('ERP_CURRENCY_DECIMALS', 2),
        'symbol' => env('ERP_CURRENCY_SYMBOL', 'ر.س'),
    ],

    'locales' => ['ar', 'en'],

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Spreadsheet imports (Phase 5)
    'imports' => [
        'max_file_kb' => 10240,
        'max_rows' => 20000,
        'chunk' => 200, // rows per processing step (browser polling or cron)
        // Hosts that remote spreadsheet links / image URLs may point to (exact or "*.domain").
        'allowed_hosts' => array_filter(array_merge(
            ['docs.google.com', '*.googleusercontent.com', 'sheets.googleapis.com'],
            explode(',', (string) env('ERP_IMPORT_ALLOWED_HOSTS', '')),
        )),
        // Absolute path to a Google service-account JSON key (outside public/). Optional.
        'google_service_account' => env('GOOGLE_SERVICE_ACCOUNT_JSON', ''),
    ],

    // Optional AI analytics (Phase 4). Only aggregated, branch-authorized figures are sent.
    'ai' => [
        'enabled' => (bool) env('ERP_AI_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ERP_AI_MODEL', 'claude-opus-5-5'),
    ],

    // Internal barcode prefix (EAN-13 in-store range 20-29) used when a product has no barcode.
    'barcode_prefix' => env('ERP_BARCODE_PREFIX', '29'),

    'uploads' => [
        'image_max_kb' => 2048,
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    // Default values for DB-backed settings (App\Modules\Core\Services\Settings).
    'defaults' => [
        'tax_enabled' => false,
        'prices_include_tax' => true,
        'costing_method' => 'weighted_average', // weighted_average | last_purchase
        'walk_in_customer_enabled' => true,
        'max_discount_percent' => 10, // for users with sales.discount but without sales.discount-unlimited
        'company_name' => env('APP_NAME', 'Eyewear ERP'),
        'company_tax_number' => '',
        'invoice_footer' => '',
        // Phase 3
        'cash_shift_required' => true,   // POS cash receipts require an open cashier shift
        'payment_terms_days' => 30,      // receivables older than this are "overdue"
        // Phase 4 reorder suggestions
        'reorder_lead_days' => 14,
        'reorder_safety_days' => 7,
        // Phase 6 offline POS: 'review' = accept oversold offline sales and flag them; 'reject' = keep them out and flag
        'offline_oversell_policy' => 'review',
        'offline_max_age_days' => 7,
        // Eyewear module
        'loyalty_enabled' => false,
        'loyalty_points_per_unit' => 1,       // points per 1 currency unit (after discounts)
        'loyalty_point_value' => 5,           // minor units one point is worth when redeemed
        'loyalty_min_redeem' => 100,
        'loyalty_max_redeem_percent' => 50,   // max share of an invoice payable with points
        'loyalty_silver_threshold' => 1000,   // lifetime points → silver (×1.25)
        'loyalty_gold_threshold' => 5000,     // lifetime points → gold (×1.5)
        'repair_service_product_id' => null,  // service product used to invoice repairs
    ],
];

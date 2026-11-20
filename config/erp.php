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
        // Public website (edited from Website → Website settings)
        'website_enabled' => true,
        'website_show_prices' => true,
        'website_show_stock' => true,
        'website_hero_title' => 'اكتشف تجربة بصرية مميزة مع أفضل النظارات والعدسات الطبية والشمسية',
        'website_hero_subtitle' => 'خدمة ما بعد البيع المجانية وخصومات حصرية لعملائنا',
        'website_about' => 'النور للبصريات شركة رائدة في مجال النظارات والعناية بالعين في مصر. نقدم النظارات الطبية ونظارات الشمس والعدسات الطبية والعدسات اللاصقة والعيون الصناعية، مع استشارات طبية للعيون. مع النور للبصريات، سننير لك حياتك!',
        'website_tagline' => 'نُنير لك حياتك',
        'website_hours' => 'يومياً ما عدا الجمعة، من 10 صباحاً إلى 10 مساءً',
        'website_phone' => '',
        'website_whatsapp' => '',             // local or international number; shown as a WhatsApp button
        'website_whatsapp_country_code' => '20',
        'website_facebook' => '',
        'website_instagram' => '',
        'website_tiktok' => '',
    ],

    // Public website routing. With WEBSITE_DOMAIN set (e.g. alnouroptical.com) the site is served at that
    // domain's root; otherwise it lives under /{prefix} on the ERP domain.
    'website' => [
        'domain' => env('WEBSITE_DOMAIN'),
        'prefix' => env('WEBSITE_PREFIX', 'shop'),
        'per_page' => 24,
    ],
];

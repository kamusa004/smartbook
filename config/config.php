<?php
/**
 * SmartBook Solutions - application configuration.
 * Copy to config.php and edit for your server. Never commit real secrets.
 */
return [
    'app' => [
        'name'           => 'SmartBook Solutions',
        'tagline'        => 'Your Business, Under Control.',
        'env'            => 'local',              // local | production
        'base_url'       => 'http://127.0.0.1:8080',
        'timezone'       => 'Africa/Kampala',
        'currency'       => 'UGX',
        'locales'        => ['en', 'lg', 'nyn'], // English, Luganda, Runyankole
        'default_locale' => 'en',
        'debug'          => true,
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3307,
        'name'    => 'smartbook',
        'user'    => 'smartbook',
        'pass'    => 'SmartBook#2026',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'token_ttl_hours'    => 72,
        'bcrypt_cost'        => 11,      // low-end hosting friendly; raise to 12 if CPU allows
        'max_login_attempts' => 8,
        'login_window_min'   => 15,
        'csrf'               => true,
        'force_https'        => false,
        'session_name'       => 'smartbook_sid',
    ],

    'subscription' => [
        'default_plan'      => 'basic',
        'trial_days'        => 14,
        'default_grace_days'=> 5,    // days of full access after expiry (billing grace)
        'offline_grace_days'=> 3,    // days a device may keep recording offline after expiry
        'annual_discount'   => 2,    // annual billing = 10 months for 12
    ],

    /**
     * Payment providers. Manual verification is the default and is fully
     * functional. The MoMo/Airtel adapters only activate when you supply your
     * own credentials from the operator - no endpoints or keys are invented here.
     */
    'payments' => [
        'provider'          => 'manual',   // manual | mtn_momo | airtel_money
        'merchant_name'     => 'SmartBook Solutions',
        'merchant_code'     => 'SBS-001',
        'mtn' => [
            'enabled'        => false,
            'base_url'       => '',   // e.g. https://sandbox.momodeveloper.mtn.com (your operator value)
            'subscription_key'=> '',
            'api_user'       => '',
            'api_key'        => '',
            'target_env'     => 'sandbox',
        ],
        'airtel' => [
            'enabled'        => false,
            'base_url'       => '',
            'client_id'      => '',
            'client_secret'  => '',
            'country'        => 'UG',
            'currency'       => 'UGX',
        ],
    ],

    'analytics' => [
        'enabled'   => true,
        'base_url'  => 'http://127.0.0.1:8000',  // Python analytics service
        'timeout_s' => 3,
    ],

    'messaging' => [
        'sms_provider'   => 'none',   // none | generic_http
        'sms_endpoint'   => '',
        'sms_api_key'    => '',
        'whatsapp_url'   => 'https://wa.me/{phone}?text={message}', // deep link only, no API key needed
    ],

    'uploads' => [
        'dir'        => 'storage/uploads',
        'max_bytes'  => 2097152,
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];

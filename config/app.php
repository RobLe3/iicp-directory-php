<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'iicp_version' => 'v1.10.55',

    // #373 Phase B: whether the directory origin has IPv6 egress. DomainFactory
    // shared hosting does NOT — probing IPv6-literal node endpoints from there only
    // records false negatives, so iicp:probe-nodes skips them unless this is set.
    'iicp_probe_ipv6_egress' => (bool) env('IICP_PROBE_IPV6_EGRESS', false),

    // #310 founder recognition (§5.4). The founder-era anchor: founder tier windows
    // (Genesis-50 ≤3mo / Founders-500 ≤6mo / Founders-1000 ≤12mo) are measured from this
    // instant. Maintainer-ratified 2026-06-06 = 1780704000000 (2026-06-06T00:00:00Z).
    // PERMANENT once founders are minted — never change it (it would re-tier immutable slots).
    'iicp_genesis_ms' => (int) env('IICP_GENESIS_MS', 1780704000000),

    // The founder's operator public key (ed25519, base64) — ordinal #1, reserved for the
    // maintainer by directive 2026-06-06. The cryptographic operator_pubkey is the unique
    // identifier (operator_contact is not sent to the directory, so #1 is resolved by key, not
    // by contact). Never served publicly. Overridable via env if the founder key is rotated.
    'iicp_founder_one_pubkey' => env('IICP_FOUNDER_ONE_PUBKEY', 'sbPEVw2mnmrWsZR7NuNmOb9q0mwhHH++z0cENY0gtbI='),

    'genesis_ed25519_secret_key' => env('IICP_GENESIS_ED25519_SECRET_KEY'),

    // HMAC secret for the /_deploy/migrate endpoint (DeployMigrateController).
    // Empty = endpoint returns 503 (fail-closed). Generate via:
    //   openssl rand -hex 32
    // and set both .env (IICP_DEPLOY_SECRET=…) and the matching client-side
    // value in deploy/.credentials/deploy_secret.sh.
    'deploy_secret' => env('IICP_DEPLOY_SECRET', ''),

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Compliance Mode
    |--------------------------------------------------------------------------
    | Applies regulation-specific defaults. Run `php artisan pixelite:install`
    | for an interactive setup wizard.
    |
    | Options: gdpr, ccpa, kvkk, multi, none
    | Individual settings below take precedence over the mode-level defaults.
    */
    'compliance_mode' => env('PIXELITE_COMPLIANCE_MODE', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Consent Management
    |--------------------------------------------------------------------------
    | GDPR / KVKK: required=true, default=denied  (opt-in model)
    | CCPA        : required=false                (opt-out model, see rights.opt_out_enabled)
    |
    | Set the consent cookie from your cookie-consent banner before the page
    | tracking middleware runs. Accepted truthy values: 1, true, granted, yes.
    */
    'consent' => [
        'required'    => env('PIXELITE_CONSENT_REQUIRED', false),
        'cookie_name' => env('PIXELITE_CONSENT_COOKIE', 'pixelite_consent'),
        'default'     => env('PIXELITE_CONSENT_DEFAULT', 'granted'), // granted | denied
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Address Anonymization
    |--------------------------------------------------------------------------
    | none    — store the full IP address as-is
    | partial — mask the last octet  (IPv4: 1.2.3.0 | IPv6: /64 prefix kept)
    | full    — do not store the IP address at all
    |
    | Note: GeoIP lookup happens before anonymization, so country/city data
    | is still resolved even under full anonymization.
    */
    'ip' => [
        'anonymization' => env('PIXELITE_IP_ANONYMIZATION', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Collection Toggles
    |--------------------------------------------------------------------------
    | Disable individual data points to minimize the collection surface and
    | reduce privacy risk. Disabled fields are never written to the database.
    */
    'collect' => [
        'geo'        => env('PIXELITE_COLLECT_GEO', true),
        'user_agent' => env('PIXELITE_COLLECT_USER_AGENT', true),
        'referer'    => env('PIXELITE_COLLECT_REFERER', true),
        'utm'        => env('PIXELITE_COLLECT_UTM', true),
        'click_ids'  => env('PIXELITE_COLLECT_CLICK_IDS', true),
        'screen'     => env('PIXELITE_COLLECT_SCREEN', true),
        'timezone'   => env('PIXELITE_COLLECT_TIMEZONE', true),
        'total_time' => env('PIXELITE_COLLECT_TOTAL_TIME', true),
        'locale'     => env('PIXELITE_COLLECT_LOCALE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    | Automatically delete records older than the thresholds below.
    | Schedule `pixelite:purge-data` to run daily for this to take effect.
    | Set a value to 0 to retain indefinitely.
    */
    'retention' => [
        'enabled'     => env('PIXELITE_RETENTION_ENABLED', false),
        'raw_hours'   => env('PIXELITE_RETENTION_RAW_HOURS', 24),
        'visits_days' => env('PIXELITE_RETENTION_VISITS_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Rights & Opt-out
    |--------------------------------------------------------------------------
    | opt_out_enabled: honour a browser-set opt-out cookie (CCPA "Do Not Sell").
    | opt_out_cookie:  set this cookie to any truthy value to suppress tracking.
    | deletion_enabled / export_enabled: enable the artisan commands for
    |   GDPR Art.17 (right to erasure) and Art.20 (data portability).
    */
    'rights' => [
        'opt_out_enabled'  => env('PIXELITE_OPT_OUT_ENABLED', false),
        'opt_out_cookie'   => env('PIXELITE_OPT_OUT_COOKIE', 'pixelite_optout'),
        'deletion_enabled' => env('PIXELITE_DELETION_ENABLED', true),
        'export_enabled'   => env('PIXELITE_EXPORT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Profiling
    |--------------------------------------------------------------------------
    | cross_session: store user_id with every visit so authenticated users can
    |   be analysed across sessions. Disable under GDPR/KVKK unless explicit
    |   profiling consent is obtained.
    | behavioral: collect engagement signals (time on page, screen dimensions).
    */
    'profiling' => [
        'cross_session' => env('PIXELITE_CROSS_SESSION', true),
        'behavioral'    => env('PIXELITE_BEHAVIORAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy & Custom Identifiers
    |--------------------------------------------------------------------------
    | team_id   — store a team / organisation ID alongside every visit.
    |             Mirrors user_id but for team-scoped multi-tenant applications.
    |
    | custom_id — store any string identifier (e.g. shop_id, account_id).
    |             The `label` is shown in command output and the install wizard.
    |             The `resolver` uses dot-notation to pull the value at runtime:
    |               user.shop_id     → auth()->user()?->shop_id
    |               session.shop_id  → $request->session()->get('shop_id')
    |               request.shop_id  → $request->input('shop_id')
    |               header.X-Shop-Id → $request->header('X-Shop-Id')
    */
    'tracking' => [
        'team_id' => [
            'enabled'  => env('PIXELITE_TRACK_TEAM_ID', false),
            'resolver' => env('PIXELITE_TEAM_ID_RESOLVER', 'user.team_id'),
        ],
        'custom_id' => [
            'enabled'  => env('PIXELITE_TRACK_CUSTOM_ID', false),
            'label'    => env('PIXELITE_CUSTOM_ID_LABEL', 'custom_id'),
            'resolver' => env('PIXELITE_CUSTOM_ID_RESOLVER', 'user.custom_id'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoIP Database Path
    |--------------------------------------------------------------------------
    | Path to the MaxMind GeoLite2-City.mmdb file.
    | Free download: https://dev.maxmind.com/geoip/geolite2-free-geolocation-data
    */
    'geo_db_path' => env('PIXELITE_GEO_DB_PATH', storage_path('app/private/GeoLite2-City.mmdb')),

];

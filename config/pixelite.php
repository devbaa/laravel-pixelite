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
    | Identifier Formats
    |--------------------------------------------------------------------------
    | Configure the DB column type for each tracked identifier to match your
    | Laravel application's primary key format. Set during `pixelite:install`.
    |
    | user_id format  — integer (default bigint) | uuid (char 36) | ulid (char 26)
    | team_id format  — same options as user_id
    | custom_id format— string (varchar 255) | uuid (char 36) | ulid (char 26)
    |                   | integer (bigint unsigned)
    |
    | ⚠  Changing these after running migrations requires a manual migration
    |    to alter the column types.
    */

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy & Custom Identifiers
    |--------------------------------------------------------------------------
    | user_id  — built-in; links visits to auth()->id(). Format matches above.
    |
    | team_id  — store a named team / organisation ID alongside every visit.
    |            label    : human-readable name shown in reports and commands.
    |            resolver : dot-notation source (see custom_id docs below).
    |            format   : column type (integer | uuid | ulid).
    |
    | custom_id— store any additional identifier (e.g. shop_id, account_id).
    |            label    : the identifier's name (e.g. "shop_id").
    |            resolver : dot-notation source:
    |              user.shop_id     → auth()->user()?->shop_id
    |              session.shop_id  → $request->session()->get('shop_id')
    |              request.shop_id  → $request->input('shop_id')
    |              header.X-Shop-Id → $request->header('X-Shop-Id')
    |            format   : column type (string | uuid | ulid | integer).
    */
    'tracking' => [
        'user_id' => [
            'format' => env('PIXELITE_USER_ID_FORMAT', 'integer'), // integer | uuid | ulid
        ],
        'team_id' => [
            'enabled'  => env('PIXELITE_TRACK_TEAM_ID', false),
            'label'    => env('PIXELITE_TEAM_ID_LABEL', 'team_id'),
            'resolver' => env('PIXELITE_TEAM_ID_RESOLVER', 'user.team_id'),
            'format'   => env('PIXELITE_TEAM_ID_FORMAT', 'integer'), // integer | uuid | ulid
        ],
        'custom_id' => [
            'enabled'  => env('PIXELITE_TRACK_CUSTOM_ID', false),
            'label'    => env('PIXELITE_CUSTOM_ID_LABEL', 'custom_id'),
            'resolver' => env('PIXELITE_CUSTOM_ID_RESOLVER', 'user.custom_id'),
            'format'   => env('PIXELITE_CUSTOM_ID_FORMAT', 'string'), // string | uuid | ulid | integer
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

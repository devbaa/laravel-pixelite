# Laravel Pixelite

A privacy-first visit analytics package for Laravel. Captures page visits via middleware, processes them asynchronously through a job pipeline, and ships with a full compliance toolkit for GDPR, CCPA, and KVKK.

## Features

- **Two-stage pipeline** — lightweight middleware captures raw visits; a queued job normalises them in batches
- **Privacy compliance** — interactive install wizard with presets for GDPR, CCPA, KVKK
- **IP anonymisation** — none / partial (last octet masked) / full (not stored)
- **Consent management** — opt-in cookie gate (GDPR/KVKK) or opt-out cookie (CCPA)
- **Data retention** — automatic purge of raw and processed records on a schedule
- **Right to erasure** — delete all data for a user, team, session, or custom identifier
- **Data portability** — export visit history to JSON
- **Multi-tenancy** — store `team_id` and a configurable `custom_id` (e.g. `shop_id`) with every visit
- **GeoIP** — country, region, city from MaxMind GeoLite2
- **User agent parsing** — device category, OS, browser (no third-party library)
- **UTM & click ID tracking** — gclid, fbclid, msclkid, ttclid, li_fat_id

---

## Requirements

- PHP 8.2+
- Laravel 10+
- MaxMind GeoLite2-City database (free)

---

## Installation

```bash
composer require boralp/laravel-pixelite
```

### Interactive setup wizard

```bash
php artisan pixelite:install
```

The wizard asks which privacy regulation applies and configures everything — env vars, migrations, and assets — in one pass.

```
Which privacy regulation applies to your application?
  [0] None — No restrictions — collect all data
  [1] GDPR — EU General Data Protection Regulation
  [2] CCPA — California Consumer Privacy Act
  [3] KVKK — Turkish Personal Data Protection Law
  [4] Multiple regulations — Apply strictest settings (GDPR + CCPA + KVKK)
```

To skip the wizard and apply a preset non-interactively:

```bash
php artisan pixelite:install --mode=gdpr
```

### Manual setup (without the wizard)

```bash
# Publish config
php artisan vendor:publish --tag=pixelite-config

# Publish and run migrations
php artisan vendor:publish --tag=pixelite-migrations
php artisan migrate

# Publish JS asset
php artisan vendor:publish --tag=pixelite-assets
```

---

## Middleware

Add the `pixelite.visit` middleware to any route group you want to track:

```php
// routes/web.php
Route::middleware(['web', 'pixelite.visit'])->group(function () {
    Route::get('/', HomeController::class);
    // …
});
```

Or globally in `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Boralp\Pixelite\Middleware\TrackVisit::class);
})
```

The middleware automatically skips: JSON/AJAX requests, `api/*`, `admin/*`, POST requests, and routes with no name.

---

## JavaScript tracking

Include the tracking script in your Blade layout to collect time-on-page, screen dimensions, and timezone:

```html
<script src="/js/pixelite/pixelite.min.js"></script>
<script>
    pixelite.init('{{ session("pixelite_trace_id") }}');
</script>
```

The script sends a heartbeat every 20 seconds while the tab is visible and posts final data when the user leaves.

---

## GeoIP setup

Download the free **GeoLite2-City** database from [maxmind.com](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data) and set the path:

```env
PIXELITE_GEO_DB_PATH=/path/to/GeoLite2-City.mmdb
```

The default path is `storage/app/private/GeoLite2-City.mmdb`.

---

## Processing visits

Raw visit records are normalised by a queued job. Dispatch it from a scheduler or run it manually:

```php
// App\Console\Kernel — recommended
$schedule->job(new \Boralp\Pixelite\Jobs\ProcessVisitRaw(500))->everyMinute();

// Or via artisan
php artisan pixelite:process-visits --batch-size=500
```

---

## Multi-tenancy & custom identifiers

Pixelite can store a `team_id` and one arbitrary string identifier (e.g. `shop_id`) alongside every visit. Both are indexed and available in all deletion/export commands.

### team_id

Enable in `.env`:

```env
PIXELITE_TRACK_TEAM_ID=true
PIXELITE_TEAM_ID_RESOLVER=user.team_id
```

`user.team_id` reads `auth()->user()->team_id` at request time. See resolver syntax below.

### Custom identifier (e.g. shop_id)

```env
PIXELITE_TRACK_CUSTOM_ID=true
PIXELITE_CUSTOM_ID_LABEL=shop_id
PIXELITE_CUSTOM_ID_RESOLVER=user.shop_id
```

`PIXELITE_CUSTOM_ID_LABEL` is the human-readable name shown in command output. The value is always stored in the `custom_id` column.

### Resolver syntax

The resolver is a `source.key` string evaluated at runtime:

| Resolver | Resolved value |
|---|---|
| `user.team_id` | `auth()->user()?->team_id` |
| `user.shop_id` | `auth()->user()?->shop_id` |
| `session.shop_id` | `$request->session()->get('shop_id')` |
| `request.shop_id` | `$request->input('shop_id')` |
| `header.X-Shop-Id` | `$request->header('X-Shop-Id')` |

---

## Privacy compliance

### Consent management (GDPR / KVKK opt-in)

```env
PIXELITE_CONSENT_REQUIRED=true
PIXELITE_CONSENT_COOKIE=pixelite_consent
PIXELITE_CONSENT_DEFAULT=denied
```

Set the cookie from your consent banner before the page loads:

```js
// When the user clicks "Accept"
document.cookie = 'pixelite_consent=granted; path=/; max-age=31536000; SameSite=Lax';

// When the user clicks "Reject"
document.cookie = 'pixelite_consent=denied; path=/; max-age=31536000; SameSite=Lax';
```

Accepted truthy values: `1`, `true`, `granted`, `yes`.

### Opt-out (CCPA "Do Not Sell / Share")

```env
PIXELITE_OPT_OUT_ENABLED=true
PIXELITE_OPT_OUT_COOKIE=pixelite_optout
```

Set the opt-out cookie to any truthy value to suppress tracking for that browser.

### IP anonymisation

```env
# none    — store full IP (default)
# partial — mask last octet: 1.2.3.0 (IPv4), /64 prefix (IPv6)
# full    — do not store IP at all
PIXELITE_IP_ANONYMIZATION=partial
```

GeoIP lookup happens before anonymisation, so country/city data is still resolved under `partial` and `full` modes.

### Compliance presets at a glance

| Setting | GDPR | CCPA | KVKK | Multi | None |
|---|:---:|:---:|:---:|:---:|:---:|
| Consent required | ✓ | — | ✓ | ✓ | — |
| Default (no cookie) | denied | granted | denied | denied | granted |
| IP anonymisation | partial | none | partial | partial | none |
| Retention | 365 days | 365 days | 730 days | 365 days | off |
| Opt-out | ✓ | ✓ | ✓ | ✓ | — |
| Cross-session profiling | — | ✓ | — | — | ✓ |
| Screen dimensions | — | ✓ | — | — | ✓ |

---

## Data collection toggles

Disable individual fields to minimise the data surface:

```env
PIXELITE_COLLECT_GEO=true
PIXELITE_COLLECT_USER_AGENT=true
PIXELITE_COLLECT_REFERER=true
PIXELITE_COLLECT_UTM=true
PIXELITE_COLLECT_CLICK_IDS=true
PIXELITE_COLLECT_SCREEN=true
PIXELITE_COLLECT_TIMEZONE=true
PIXELITE_COLLECT_TOTAL_TIME=true
PIXELITE_COLLECT_LOCALE=true
```

Disabled fields are never written to the database, not even to the raw table.

---

## Data retention

```env
PIXELITE_RETENTION_ENABLED=true
PIXELITE_RETENTION_RAW_HOURS=24      # delete raw visits older than N hours
PIXELITE_RETENTION_VISITS_DAYS=365   # delete processed visits older than N days (0 = keep forever)
```

Schedule the purge command to run daily:

```php
// App\Console\Kernel
$schedule->command('pixelite:purge-data --force')->daily();
```

---

## Artisan commands

### `pixelite:install`

Interactive setup wizard.

```bash
php artisan pixelite:install
php artisan pixelite:install --mode=gdpr          # non-interactive preset
php artisan pixelite:install --no-publish          # skip migration/asset publishing
```

### `pixelite:process-visits`

Synchronously process a batch of raw visits.

```bash
php artisan pixelite:process-visits --batch-size=500
```

### `pixelite:purge-data`

Delete records exceeding the retention thresholds.

```bash
php artisan pixelite:purge-data
php artisan pixelite:purge-data --force   # skip confirmation
```

### `pixelite:delete-user-data`

Permanently erase all visit data for a given identifier (GDPR Art.17 — Right to Erasure).

```bash
# By user ID (default)
php artisan pixelite:delete-user-data 42

# By team ID
php artisan pixelite:delete-user-data 7 --type=team_id

# By session ID (anonymous users)
php artisan pixelite:delete-user-data "abc123xyz" --type=session_id

# By custom identifier (e.g. shop_id)
php artisan pixelite:delete-user-data "shop_99" --type=custom_id

# Skip confirmation prompt
php artisan pixelite:delete-user-data 42 --force
```

Every erasure is logged to the `pixelite_dsr` audit table with a timestamp and record count.

### `pixelite:export-user-data`

Export all visit data as JSON (GDPR Art.20 — Data Portability).

```bash
# To stdout
php artisan pixelite:export-user-data 42 --pretty

# By team ID
php artisan pixelite:export-user-data 7 --type=team_id --pretty

# To a file
php artisan pixelite:export-user-data 42 --output=/tmp/user-42.json --pretty
```

---

## Database schema

### `visit_raws` (temporary, deleted after processing)

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | Authenticated user (nullable) |
| `team_id` | bigint | Team/organisation (nullable) |
| `session_id` | varchar | Laravel session ID |
| `custom_id` | varchar 255 | Custom identifier, e.g. shop_id (nullable) |
| `route_name` | varchar | Laravel named route |
| `route_params` | json | Route parameters |
| `ip` | binary 16 | IPv4-mapped IPv6 binary |
| `user_agent` | text | Raw user-agent string |
| `payload` | json | UTM, click IDs, referrer, locale |
| `payload_js` | json | Screen dimensions, timezone (from JS) |
| `total_time` | smallint | Seconds visible (from JS) |
| `created_at` | timestamp | Capture time |

### `visits` (normalised, permanent)

Same identifier columns as above, plus:

| Column | Type | Description |
|---|---|---|
| `geo_id` | FK → `geos` | Country, region, city, coordinates |
| `user_agent_id` | FK → `user_agents` | Parsed device/OS/browser |
| `referer_id` | FK → `referers` | Full referrer URL |
| `referer_domain` | varchar | Referrer domain (denormalised for fast filtering) |
| `utm_id` | FK → `utms` | UTM parameters |
| `click_id` | FK → `click_ids` | gclid, fbclid, msclkid, ttclid, li_fat_id |
| `screen_id` | FK → `screens` | Screen/viewport dimensions |
| `country_code` | char 2 | ISO 3166-1 (denormalised) |
| `device_category` | varchar | desktop / mobile / tablet / bot / tv |
| `os_name` | varchar | Windows / macOS / iOS / Android … |
| `timezone` | integer | UTC offset in minutes |
| `locale` | varchar | Accept-Language preference |
| `total_time` | smallint | Seconds on page |

### `pixelite_dsr` (data subject request audit log)

| Column | Type | Description |
|---|---|---|
| `type` | varchar | `deletion` or `export` |
| `identifier` | varchar | The actual value erased/exported |
| `identifier_type` | varchar | `user_id`, `team_id`, `session_id`, custom label |
| `records_affected` | int | Row count |
| `status` | varchar | `completed` |
| `requested_at` | timestamp | When the command ran |
| `completed_at` | timestamp | When it finished |

---

## Full configuration reference

Publish the config file to customise beyond env vars:

```bash
php artisan vendor:publish --tag=pixelite-config
```

```php
// config/pixelite.php
return [
    'compliance_mode' => env('PIXELITE_COMPLIANCE_MODE', 'none'), // gdpr|ccpa|kvkk|multi|none

    'consent' => [
        'required'    => env('PIXELITE_CONSENT_REQUIRED', false),
        'cookie_name' => env('PIXELITE_CONSENT_COOKIE', 'pixelite_consent'),
        'default'     => env('PIXELITE_CONSENT_DEFAULT', 'granted'), // granted|denied
    ],

    'ip' => [
        'anonymization' => env('PIXELITE_IP_ANONYMIZATION', 'none'), // none|partial|full
    ],

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

    'retention' => [
        'enabled'     => env('PIXELITE_RETENTION_ENABLED', false),
        'raw_hours'   => env('PIXELITE_RETENTION_RAW_HOURS', 24),
        'visits_days' => env('PIXELITE_RETENTION_VISITS_DAYS', 365),
    ],

    'rights' => [
        'opt_out_enabled'  => env('PIXELITE_OPT_OUT_ENABLED', false),
        'opt_out_cookie'   => env('PIXELITE_OPT_OUT_COOKIE', 'pixelite_optout'),
        'deletion_enabled' => env('PIXELITE_DELETION_ENABLED', true),
        'export_enabled'   => env('PIXELITE_EXPORT_ENABLED', true),
    ],

    'profiling' => [
        'cross_session' => env('PIXELITE_CROSS_SESSION', true),
        'behavioral'    => env('PIXELITE_BEHAVIORAL', true),
    ],

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

    'geo_db_path' => env('PIXELITE_GEO_DB_PATH', storage_path('app/private/GeoLite2-City.mmdb')),
];
```

---

## Example: shop_id tracking for a SaaS platform

```bash
php artisan pixelite:install
```

When asked about the custom identifier:

```
Track a custom string identifier? (e.g. shop_id, account_id, tenant_id) [no]
> yes

What is the human-readable name for this identifier?
> shop_id

How is it resolved?
> user.shop_id
```

This writes to `.env`:

```env
PIXELITE_TRACK_CUSTOM_ID=true
PIXELITE_CUSTOM_ID_LABEL=shop_id
PIXELITE_CUSTOM_ID_RESOLVER=user.shop_id
```

Every visit now captures `auth()->user()->shop_id`. To erase all visits for a shop:

```bash
php artisan pixelite:delete-user-data "my-shop-handle" --type=custom_id --force
```

---

## Upgrading existing installations

If you installed Pixelite before `team_id` and `custom_id` were added, run the additive migration:

```bash
php artisan vendor:publish --tag=pixelite-migrations
php artisan migrate
```

The migration uses `hasColumn` guards and is safe to run multiple times.

---

## License

MIT

# Laravel Pixelite

A privacy-first visit analytics package for Laravel. Captures page visits via middleware, processes them asynchronously through a high-performance batch pipeline, and ships with a full compliance toolkit for GDPR, CCPA, and KVKK.

## Features

- **Two-stage pipeline** — lightweight middleware captures raw visits; a queued job normalises them in batches (~15 queries per batch regardless of size)
- **Privacy compliance** — interactive install wizard with presets for GDPR, CCPA, KVKK
- **Flexible ID formats** — user_id, team_id, and custom_id support `integer`, `uuid`, and `ulid`; custom_id also supports `string`
- **IP anonymisation** — none / partial (last octet masked) / full (not stored)
- **Consent management** — opt-in cookie gate (GDPR/KVKK) or opt-out cookie (CCPA)
- **Data retention** — automatic purge of raw and processed records on a schedule
- **Right to erasure** — delete all data for a user, team, session, or custom identifier
- **Data portability** — export visit history to JSON
- **Multi-tenancy** — named `team_id` and a fully configurable `custom_id` (e.g. `shop_id`) with every visit
- **GeoIP** — country, region, city from MaxMind GeoLite2
- **User agent parsing** — device category, OS, browser (no third-party library)
- **UTM & click ID tracking** — gclid, fbclid, msclkid, ttclid, li_fat_id
- **Supervisor-ready daemon** — continuous processing with PCNTL signal handling and memory-limit restart

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

The wizard runs two sections:

**1 — Compliance preset** — which privacy regulation applies?

```
Which privacy regulation applies to your application?
  [0] None — No restrictions — collect all data
  [1] GDPR — EU General Data Protection Regulation
  [2] CCPA — California Consumer Privacy Act
  [3] KVKK — Turkish Personal Data Protection Law
  [4] Multiple regulations — Apply strictest settings (GDPR + CCPA + KVKK)
```

**2 — Identifier Formats** — always asked, configures DB column types:

```
── Identifier Formats ──────────────────────────────────────────────────────
Match these to your Laravel application's primary key format.
This determines the column types in the published migrations.

What format are your user IDs? (auth()->id())
  [0] integer — bigint unsigned (default Laravel, e.g. 1, 42)
  [1] uuid    — char(36)  (HasUuids, e.g. "550e8400-e29b-41d4...")
  [2] ulid    — char(26)  (HasUlids, e.g. "01ARZ3NDEKTSV4RRFFQ...")

Track a team / organisation ID with each visit? (yes/no) [no]

Track a custom identifier? (e.g. shop_id, account_id, tenant_id) (yes/no) [no]
> yes

What should this identifier be called?
> shop_id

What format is shop_id?
  [0] string  — varchar(255) for any text value
  [1] uuid    — char(36)
  [2] ulid    — char(26)
  [3] integer — bigint unsigned for numeric IDs

How is shop_id resolved?
> user.shop_id
```

After the wizard writes your `.env`, the published migrations use those format settings to create the right column types.

#### Non-interactive flags

All wizard settings can be passed as flags:

```bash
php artisan pixelite:install \
  --mode=gdpr \
  --user-id-format=uuid \
  --team-id-format=uuid \
  --team-id-label=organization_id \
  --custom-id-label=shop_id \
  --custom-id-format=string \
  --no-publish
```

### Manual setup (without the wizard)

```bash
# Set ID formats in .env first (determines column types in migrations)
echo "PIXELITE_USER_ID_FORMAT=integer" >> .env

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

### Queue job (recommended for most apps)

```php
// App\Console\Kernel
$schedule->job(new \Boralp\Pixelite\Jobs\ProcessVisitRaw(200))->everyMinute();
```

### Supervisor daemon (recommended for high-traffic apps)

The daemon processes visits continuously with no per-minute latency and gracefully shuts down on `SIGTERM`/`SIGINT`:

```bash
php artisan pixelite:daemon --batch-size=200 --sleep=500 --max-memory=128
```

Supervisor config (`/etc/supervisor/conf.d/pixelite.conf`):

```ini
[program:pixelite-daemon]
command=php /var/www/artisan pixelite:daemon --batch-size=200
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=30
stdout_logfile=/var/log/pixelite-daemon.log
```

`numprocs=2` is safe — concurrent workers use `SELECT FOR UPDATE` to claim batches atomically with no duplicates.

---

## Identifier formats

Pixelite stores `user_id`, `team_id`, and `custom_id` in DB columns whose type is determined at migration time from your `.env`. Match these to your Laravel app's primary key format:

| Format | Column type | Use when |
|---|---|---|
| `integer` | `bigint unsigned` | Default Laravel auto-increment IDs |
| `uuid` | `char(36)` | `HasUuids` trait, Ramsey UUID |
| `ulid` | `char(26)` | `HasUlids` trait |
| `string` | `varchar(255)` | `custom_id` only — any text value |

Set in `.env` (or let the wizard ask):

```env
PIXELITE_USER_ID_FORMAT=uuid
PIXELITE_TEAM_ID_FORMAT=uuid
PIXELITE_CUSTOM_ID_FORMAT=string
```

> **Important:** Changing formats after running migrations requires a manual `ALTER TABLE`. Set these before running `migrate`.

### UUID example

If your `User` model uses `HasUuids`:

```bash
php artisan pixelite:install --user-id-format=uuid
```

Pixelite stores `auth()->id()` as a 36-char string in a `char(36)` column — no casting needed.

---

## Multi-tenancy & custom identifiers

### team_id (with custom label)

Track an organisation/workspace/tenant ID with every visit:

```env
PIXELITE_TRACK_TEAM_ID=true
PIXELITE_TEAM_ID_LABEL=organization_id   # shown in reports and commands
PIXELITE_TEAM_ID_FORMAT=integer
PIXELITE_TEAM_ID_RESOLVER=user.team_id
```

The `PIXELITE_TEAM_ID_LABEL` is used only for human-readable output; the column is always named `team_id` in the database.

### custom_id (named and typed)

Track any additional identifier with a name and format of your choice:

```env
PIXELITE_TRACK_CUSTOM_ID=true
PIXELITE_CUSTOM_ID_LABEL=shop_id         # shown in reports; sanitized to snake_case
PIXELITE_CUSTOM_ID_FORMAT=string         # string | uuid | ulid | integer
PIXELITE_CUSTOM_ID_RESOLVER=user.shop_id
```

### Resolver syntax

The resolver is a `source.key` string evaluated at runtime on every tracked request:

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

Set the cookie from your consent banner:

```js
// User accepts
document.cookie = 'pixelite_consent=granted; path=/; max-age=31536000; SameSite=Lax';

// User rejects
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
php artisan pixelite:install --mode=gdpr              # non-interactive compliance preset
php artisan pixelite:install --user-id-format=uuid    # set user ID format
php artisan pixelite:install --custom-id-label=shop_id --custom-id-format=string
php artisan pixelite:install --no-publish             # skip migration/asset publishing
```

### `pixelite:daemon`

Continuous visit processor (Supervisor-ready).

```bash
php artisan pixelite:daemon                          # default settings
php artisan pixelite:daemon --batch-size=200         # records per cycle
php artisan pixelite:daemon --sleep=500              # ms to sleep when queue is empty
php artisan pixelite:daemon --max-memory=128         # restart after N MB (mirrors queue:work)
php artisan pixelite:daemon --once                   # process one batch then exit (for testing)
```

### `pixelite:process-visits`

Synchronously process one batch.

```bash
php artisan pixelite:process-visits --batch-size=200
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

# By UUID user ID
php artisan pixelite:delete-user-data "550e8400-e29b-41d4-a716-446655440000"

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

Column types for `user_id`, `team_id`, and `custom_id` depend on `PIXELITE_*_FORMAT` settings.

### `visit_raws` (temporary, deleted after processing)

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | int / char(36) / char(26) | Authenticated user (format-dependent) |
| `team_id` | int / char(36) / char(26) | Team/organisation (format-dependent, nullable) |
| `session_id` | varchar | Laravel session ID |
| `custom_id` | varchar / char / int | Custom identifier (format-dependent, nullable) |
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
        // user_id: format for the auth()->id() column
        'user_id' => [
            'format' => env('PIXELITE_USER_ID_FORMAT', 'integer'), // integer|uuid|ulid
        ],

        // team_id: optional named multi-tenant identifier
        'team_id' => [
            'enabled'  => env('PIXELITE_TRACK_TEAM_ID', false),
            'label'    => env('PIXELITE_TEAM_ID_LABEL', 'team_id'),
            'resolver' => env('PIXELITE_TEAM_ID_RESOLVER', 'user.team_id'),
            'format'   => env('PIXELITE_TEAM_ID_FORMAT', 'integer'), // integer|uuid|ulid
        ],

        // custom_id: one additional named identifier of any type
        'custom_id' => [
            'enabled'  => env('PIXELITE_TRACK_CUSTOM_ID', false),
            'label'    => env('PIXELITE_CUSTOM_ID_LABEL', 'custom_id'),
            'resolver' => env('PIXELITE_CUSTOM_ID_RESOLVER', 'user.custom_id'),
            'format'   => env('PIXELITE_CUSTOM_ID_FORMAT', 'string'), // string|uuid|ulid|integer
        ],
    ],

    'geo_db_path' => env('PIXELITE_GEO_DB_PATH', storage_path('app/private/GeoLite2-City.mmdb')),
];
```

---

## Example: SaaS shop analytics with UUID users

A multi-tenant SaaS where users are identified by UUID and each user belongs to a shop:

```bash
php artisan pixelite:install \
  --mode=gdpr \
  --user-id-format=uuid \
  --custom-id-label=shop_id \
  --custom-id-format=string
```

This writes to `.env`:

```env
PIXELITE_COMPLIANCE_MODE=gdpr
PIXELITE_USER_ID_FORMAT=uuid
PIXELITE_TRACK_CUSTOM_ID=true
PIXELITE_CUSTOM_ID_LABEL=shop_id
PIXELITE_CUSTOM_ID_FORMAT=string
PIXELITE_CUSTOM_ID_RESOLVER=user.shop_id
```

And publishes migrations with `char(36)` for `user_id` and `varchar(255)` for `custom_id`.

To erase all visits for a specific shop:

```bash
php artisan pixelite:delete-user-data "my-shop-handle" --type=custom_id --force
```

---

## Upgrading from earlier versions

### Adding team_id / custom_id to existing tables

If you installed Pixelite before these columns were added:

```bash
php artisan vendor:publish --tag=pixelite-migrations
php artisan migrate
```

The additive migration uses `hasColumn` guards and is safe to run multiple times.

### Adding ID format support

The new format settings default to `integer` / `string`, which matches existing column types. No migration is needed unless you want to change formats.

To change a format on an existing installation, write a migration manually:

```php
// Change user_id from integer to uuid
Schema::table('visit_raws', function (Blueprint $table): void {
    $table->dropColumn('user_id');
});
Schema::table('visit_raws', function (Blueprint $table): void {
    $table->char('user_id', 36)->nullable()->index()->after('id');
});
// Repeat for the visits table
```

---

## License

MIT

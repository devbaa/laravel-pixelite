<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'pixelite:install
        {--mode=              : Compliance preset: gdpr, ccpa, kvkk, multi, none}
        {--processing=        : How visits are processed: queue (default) | daemon}
        {--user-id-format=    : User ID column format: integer (default) | uuid | ulid}
        {--team-id-format=    : Team ID column format: integer (default) | uuid | ulid}
        {--team-id-label=     : Human name for team_id (e.g. organization_id)}
        {--custom-id-label=   : Name of the custom identifier (e.g. shop_id)}
        {--custom-id-format=  : Custom ID format: string (default) | uuid | ulid | integer}
        {--consent-cookie=    : Consent cookie name (default: pixelite_consent)}
        {--opt-out-cookie=    : Opt-out cookie name (default: pixelite_optout)}
        {--geo-db-path=       : Path to GeoLite2-City.mmdb (empty = disable geo)}
        {--no-publish         : Skip asset / migration publishing prompts}';

    protected $description = 'Interactive privacy compliance + identifier setup wizard';

    private const MODES = [
        'none'  => ['label' => 'None',                 'desc' => 'No restrictions — collect all data'],
        'gdpr'  => ['label' => 'GDPR',                 'desc' => 'EU General Data Protection Regulation'],
        'ccpa'  => ['label' => 'CCPA',                 'desc' => 'California Consumer Privacy Act'],
        'kvkk'  => ['label' => 'KVKK',                 'desc' => 'Turkish Personal Data Protection Law'],
        'multi' => ['label' => 'Multiple regulations', 'desc' => 'Apply strictest settings (GDPR + CCPA + KVKK)'],
    ];

    private const ID_FORMATS = [
        'integer' => 'integer — bigint unsigned (default Laravel, e.g. 1, 42)',
        'uuid'    => 'uuid    — char(36)  (HasUuids, e.g. "550e8400-e29b-41d4...")',
        'ulid'    => 'ulid    — char(26)  (HasUlids, e.g. "01ARZ3NDEKTSV4RRFFQ...")',
    ];

    private const CUSTOM_ID_FORMATS = [
        'string'  => 'string  — varchar(255) for any text value',
        'uuid'    => 'uuid    — char(36)',
        'ulid'    => 'ulid    — char(26)',
        'integer' => 'integer — bigint unsigned for numeric IDs',
    ];

    public function handle(): int
    {
        $this->renderHeader();

        // ── Step 1: Compliance preset ──────────────────────────────────────────

        $mode = $this->option('mode');

        if ($mode !== null && ! array_key_exists($mode, self::MODES)) {
            $this->error("Unknown mode \"{$mode}\". Valid options: ".implode(', ', array_keys(self::MODES)));

            return self::FAILURE;
        }

        if ($mode === null) {
            $mode = $this->promptForMode();
        }

        $settings = $this->presetFor($mode);

        // ── Step 2: Identifier formats ─────────────────────────────────────────

        $settings = $this->applyIdFormats($settings);

        // ── Step 3: Cookie names (contextual — shown when consent/opt-out active)

        $settings = $this->promptForCookieNames($settings);

        // ── Step 4: Processing strategy ────────────────────────────────────────

        $processing = $this->promptForProcessingStrategy();

        // ── Step 5: GeoIP database ─────────────────────────────────────────────

        $settings = $this->promptForGeoPath($settings);

        // ── Step 6: Summary + optional compliance customisation ────────────────

        $this->renderSettingsTable($mode, $settings, $processing);

        if ($this->confirm('Would you like to customise any compliance settings?', false)) {
            $settings = $this->runComplianceCustomisation($settings);
            // Re-ask cookie names if consent/opt-out was toggled during customisation
            $settings = $this->promptForCookieNames($settings);
        }

        // ── Step 7: Write .env ─────────────────────────────────────────────────

        $this->writeEnvVariables($settings);
        $this->line('');
        $this->line('  <fg=green>✓</> Environment variables written.');

        // ── Step 8: Publish assets / migrations ────────────────────────────────

        if (! $this->option('no-publish')) {
            $this->line('');
            $this->runPublishingSteps();
        }

        $this->renderNextSteps($mode, $settings, $processing);

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: compliance mode
    // ─────────────────────────────────────────────────────────────────────────

    private function promptForMode(): string
    {
        $choices = array_map(
            fn ($k, $v) => "{$v['label']} — {$v['desc']}",
            array_keys(self::MODES),
            self::MODES
        );

        $selected = $this->choice('Which privacy regulation applies to your application?', $choices, 0);
        $index    = array_search($selected, $choices, true);

        return array_keys(self::MODES)[$index];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: identifier formats
    // ─────────────────────────────────────────────────────────────────────────

    private function applyIdFormats(array $settings): array
    {
        $this->line('');
        $this->line('  <fg=cyan>── Identifier Formats ──────────────────────────────────────────────────────</>');
        $this->line('  Match these to your Laravel application\'s primary key format.');
        $this->line('  This determines the column types in the published migrations.');
        $this->line('');

        $settings['PIXELITE_USER_ID_FORMAT'] = $this->pickIdFormat(
            'What format are your user IDs? (auth()->id())',
            $this->option('user-id-format') ?? $settings['PIXELITE_USER_ID_FORMAT'],
            self::ID_FORMATS
        );

        $trackTeam = $this->confirm(
            'Track a team / organisation ID with each visit?',
            $settings['PIXELITE_TRACK_TEAM_ID'] === 'true'
        );
        $settings['PIXELITE_TRACK_TEAM_ID'] = $trackTeam ? 'true' : 'false';

        if ($trackTeam) {
            $teamLabel = $this->option('team-id-label')
                ?? $this->ask('What is this team identifier called in your app?', $settings['PIXELITE_TEAM_ID_LABEL']);
            $settings['PIXELITE_TEAM_ID_LABEL'] = $this->sanitizeLabel($teamLabel, 'team_id');

            $settings['PIXELITE_TEAM_ID_FORMAT'] = $this->pickIdFormat(
                "What format is {$settings['PIXELITE_TEAM_ID_LABEL']}?",
                $this->option('team-id-format') ?? $settings['PIXELITE_TEAM_ID_FORMAT'],
                self::ID_FORMATS
            );

            $settings['PIXELITE_TEAM_ID_RESOLVER'] = $this->ask(
                "How is {$settings['PIXELITE_TEAM_ID_LABEL']} resolved? (user.team_id | session.key | request.key | header.name)",
                $settings['PIXELITE_TEAM_ID_RESOLVER']
            );
        }

        $trackCustom = $this->confirm(
            'Track a custom identifier? (e.g. shop_id, account_id, tenant_id)',
            $settings['PIXELITE_TRACK_CUSTOM_ID'] === 'true'
        );
        $settings['PIXELITE_TRACK_CUSTOM_ID'] = $trackCustom ? 'true' : 'false';

        if ($trackCustom) {
            $customLabel = $this->option('custom-id-label')
                ?? $this->ask('What should this identifier be called? (shown in reports)', $settings['PIXELITE_CUSTOM_ID_LABEL']);
            $settings['PIXELITE_CUSTOM_ID_LABEL'] = $this->sanitizeLabel($customLabel, 'custom_id');

            $settings['PIXELITE_CUSTOM_ID_FORMAT'] = $this->pickIdFormat(
                "What format is {$settings['PIXELITE_CUSTOM_ID_LABEL']}?",
                $this->option('custom-id-format') ?? $settings['PIXELITE_CUSTOM_ID_FORMAT'],
                self::CUSTOM_ID_FORMATS
            );

            $settings['PIXELITE_CUSTOM_ID_RESOLVER'] = $this->ask(
                "How is {$settings['PIXELITE_CUSTOM_ID_LABEL']} resolved? (user.{$settings['PIXELITE_CUSTOM_ID_LABEL']} | session.key | request.key | header.name)",
                $settings['PIXELITE_CUSTOM_ID_RESOLVER']
            );
        }

        return $settings;
    }

    private function pickIdFormat(string $question, string $current, array $formats): string
    {
        $choices = array_values($formats);
        $keys    = array_keys($formats);
        $default = array_search($current, $keys, true);
        $default = ($default !== false) ? $default : 0;

        $selected = $this->choice($question, $choices, $default);
        $index    = array_search($selected, $choices, true);

        return $keys[$index];
    }

    private function sanitizeLabel(mixed $value, string $fallback): string
    {
        $label = preg_replace('/[^a-z0-9_]/i', '_', (string) ($value ?: $fallback));
        $label = strtolower(trim($label, '_'));

        return $label !== '' ? substr($label, 0, 64) : $fallback;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: cookie names
    // ─────────────────────────────────────────────────────────────────────────

    private function promptForCookieNames(array $settings): array
    {
        $needsConsent = $settings['PIXELITE_CONSENT_REQUIRED'] === 'true';
        $needsOptOut  = $settings['PIXELITE_OPT_OUT_ENABLED'] === 'true';

        if (! $needsConsent && ! $needsOptOut) {
            return $settings;
        }

        $this->line('');
        $this->line('  <fg=cyan>── Cookie Names ─────────────────────────────────────────────────────────────</>');
        $this->line('  If your app already has a consent banner, enter its existing cookie name.');
        $this->line('  Press Enter to use the Pixelite defaults.');
        $this->line('');

        if ($needsConsent) {
            $settings['PIXELITE_CONSENT_COOKIE'] = $this->option('consent-cookie')
                ?? $this->ask(
                    'Consent cookie name  (truthy values: 1, true, granted, yes)',
                    $settings['PIXELITE_CONSENT_COOKIE']
                );
        }

        if ($needsOptOut) {
            $settings['PIXELITE_OPT_OUT_COOKIE'] = $this->option('opt-out-cookie')
                ?? $this->ask(
                    'Opt-out / Do-Not-Sell cookie name',
                    $settings['PIXELITE_OPT_OUT_COOKIE']
                );
        }

        return $settings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: processing strategy
    // ─────────────────────────────────────────────────────────────────────────

    private function promptForProcessingStrategy(): string
    {
        $this->line('');
        $this->line('  <fg=cyan>── Processing Strategy ──────────────────────────────────────────────────────</>');
        $this->line('');

        $opt = $this->option('processing');
        if ($opt !== null && in_array($opt, ['queue', 'daemon'], true)) {
            $label = $opt === 'queue' ? 'Queue job' : 'Daemon';
            $this->line("  Using: <fg=green>{$label}</> (from --processing flag)");

            return $opt;
        }

        $choices = [
            'queue  — Dispatch via Laravel queue + scheduler (recommended for most apps)',
            'daemon — Long-running Supervisor process (recommended for high-traffic apps)',
        ];

        $selected = $this->choice('How should Pixelite process visit records?', $choices, 0);

        return str_starts_with($selected, 'queue') ? 'queue' : 'daemon';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: GeoIP database
    // ─────────────────────────────────────────────────────────────────────────

    private function promptForGeoPath(array $settings): array
    {
        $this->line('');
        $this->line('  <fg=cyan>── GeoIP Database ───────────────────────────────────────────────────────────</>');
        $this->line('  MaxMind GeoLite2-City resolves country, region, and city from IP addresses.');
        $this->line('  Free download (requires a free account): <fg=yellow>https://dev.maxmind.com</>');
        $this->line('');

        $path = $this->option('geo-db-path')
            ?? $this->ask('Path to GeoLite2-City.mmdb  (leave blank to disable geo collection)', '');

        $path = trim((string) $path);

        $settings['PIXELITE_GEO_DB_PATH'] = $path;
        $settings['PIXELITE_COLLECT_GEO'] = $path !== '' ? 'true' : 'false';

        if ($path === '') {
            $this->line('  <fg=yellow>⚠</> Geo collection disabled. Set PIXELITE_GEO_DB_PATH later to enable it.');
        } elseif (! file_exists($path)) {
            $this->line("  <fg=yellow>⚠</> File not found at \"{$path}\" — geo collection will be inactive until the file exists.");
        } else {
            $this->line('  <fg=green>✓</> GeoIP database found.');
        }

        return $settings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: compliance customisation
    // ─────────────────────────────────────────────────────────────────────────

    private function runComplianceCustomisation(array $settings): array
    {
        $this->line('');
        $this->line('  <fg=cyan>Customising compliance settings</> — press Enter to keep the shown default.');
        $this->line('');

        // --- Consent ---
        $settings['PIXELITE_CONSENT_REQUIRED'] = $this->confirm(
            'Require explicit consent before any tracking begins?',
            $settings['PIXELITE_CONSENT_REQUIRED'] === 'true'
        ) ? 'true' : 'false';

        if ($settings['PIXELITE_CONSENT_REQUIRED'] === 'true') {
            $settings['PIXELITE_CONSENT_DEFAULT'] = $this->choice(
                'Default when no consent cookie is present?',
                ['denied', 'granted'],
                $settings['PIXELITE_CONSENT_DEFAULT'] === 'denied' ? 0 : 1
            );
        }

        // --- IP ---
        $anonOptions = [
            'none    — Store the full IP address',
            'partial — Mask last octet  (IPv4: x.x.x.0 | IPv6: /64)',
            'full    — Do not store IP at all',
        ];
        $anonChoice = $this->choice(
            'IP address anonymisation level?',
            $anonOptions,
            match ($settings['PIXELITE_IP_ANONYMIZATION']) {
                'partial' => 1,
                'full'    => 2,
                default   => 0,
            }
        );
        $settings['PIXELITE_IP_ANONYMIZATION'] = explode(' ', trim($anonChoice))[0];

        // --- Retention ---
        $retentionEnabled = $this->confirm(
            'Enable automatic data retention (delete records older than a threshold)?',
            $settings['PIXELITE_RETENTION_ENABLED'] === 'true'
        );
        $settings['PIXELITE_RETENTION_ENABLED'] = $retentionEnabled ? 'true' : 'false';

        if ($retentionEnabled) {
            $days = $this->ask(
                'Delete processed visits after how many days? (0 = keep forever)',
                $settings['PIXELITE_RETENTION_VISITS_DAYS']
            );
            $settings['PIXELITE_RETENTION_VISITS_DAYS'] = (string) max(0, (int) $days);
        }

        // --- Profiling ---
        $settings['PIXELITE_CROSS_SESSION'] = $this->confirm(
            'Link visits to authenticated user IDs for cross-session analysis?',
            $settings['PIXELITE_CROSS_SESSION'] === 'true'
        ) ? 'true' : 'false';

        $behavioral = $this->confirm(
            'Collect behavioural data (time on page, screen dimensions)?',
            $settings['PIXELITE_BEHAVIORAL'] === 'true'
        );
        $settings['PIXELITE_BEHAVIORAL'] = $behavioral ? 'true' : 'false';
        $settings['PIXELITE_COLLECT_SCREEN'] = ($behavioral && $this->confirm(
            'Include screen / viewport dimensions?',
            $settings['PIXELITE_COLLECT_SCREEN'] === 'true'
        )) ? 'true' : 'false';

        // --- Opt-out ---
        $settings['PIXELITE_OPT_OUT_ENABLED'] = $this->confirm(
            'Honour a browser-set opt-out cookie (CCPA "Do Not Sell")?',
            $settings['PIXELITE_OPT_OUT_ENABLED'] === 'true'
        ) ? 'true' : 'false';

        return $settings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard: publishing
    // ─────────────────────────────────────────────────────────────────────────

    private function runPublishingSteps(): void
    {
        if ($this->confirm('Publish the Pixelite config file?', true)) {
            $this->callSilent('vendor:publish', ['--tag' => 'pixelite-config', '--force' => true]);
            $this->line('  <fg=green>✓</> Config published → config/pixelite.php');
        }

        if ($this->confirm('Publish Pixelite migrations?', true)) {
            $this->callSilent('vendor:publish', ['--tag' => 'pixelite-migrations']);
            $this->line('  <fg=green>✓</> Migrations published → database/migrations/');

            if ($this->confirm('Run migrations now?', true)) {
                $this->call('migrate');
            }
        }

        if ($this->confirm('Publish the Pixelite JavaScript tracking asset?', false)) {
            $this->callSilent('vendor:publish', ['--tag' => 'pixelite-assets']);
            $this->line('  <fg=green>✓</> Asset published → public/js/pixelite/pixelite.min.js');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Presets
    // ─────────────────────────────────────────────────────────────────────────

    private function presetFor(string $mode): array
    {
        $idDefaults = [
            'PIXELITE_USER_ID_FORMAT'     => 'integer',
            'PIXELITE_TRACK_TEAM_ID'      => 'false',
            'PIXELITE_TEAM_ID_LABEL'      => 'team_id',
            'PIXELITE_TEAM_ID_FORMAT'     => 'integer',
            'PIXELITE_TEAM_ID_RESOLVER'   => 'user.team_id',
            'PIXELITE_TRACK_CUSTOM_ID'    => 'false',
            'PIXELITE_CUSTOM_ID_LABEL'    => 'custom_id',
            'PIXELITE_CUSTOM_ID_FORMAT'   => 'string',
            'PIXELITE_CUSTOM_ID_RESOLVER' => 'user.custom_id',
        ];

        $cookieDefaults = [
            'PIXELITE_CONSENT_COOKIE' => 'pixelite_consent',
            'PIXELITE_OPT_OUT_COOKIE' => 'pixelite_optout',
        ];

        $compliance = match ($mode) {
            'gdpr' => [
                'PIXELITE_COMPLIANCE_MODE'       => 'gdpr',
                'PIXELITE_CONSENT_REQUIRED'      => 'true',
                'PIXELITE_CONSENT_DEFAULT'       => 'denied',
                'PIXELITE_IP_ANONYMIZATION'      => 'partial',
                'PIXELITE_RETENTION_ENABLED'     => 'true',
                'PIXELITE_RETENTION_RAW_HOURS'   => '24',
                'PIXELITE_RETENTION_VISITS_DAYS' => '365',
                'PIXELITE_OPT_OUT_ENABLED'       => 'true',
                'PIXELITE_DELETION_ENABLED'      => 'true',
                'PIXELITE_EXPORT_ENABLED'        => 'true',
                'PIXELITE_CROSS_SESSION'         => 'false',
                'PIXELITE_BEHAVIORAL'            => 'false',
                'PIXELITE_COLLECT_SCREEN'        => 'false',
            ],
            'ccpa' => [
                'PIXELITE_COMPLIANCE_MODE'       => 'ccpa',
                'PIXELITE_CONSENT_REQUIRED'      => 'false',
                'PIXELITE_CONSENT_DEFAULT'       => 'granted',
                'PIXELITE_IP_ANONYMIZATION'      => 'none',
                'PIXELITE_RETENTION_ENABLED'     => 'true',
                'PIXELITE_RETENTION_RAW_HOURS'   => '24',
                'PIXELITE_RETENTION_VISITS_DAYS' => '365',
                'PIXELITE_OPT_OUT_ENABLED'       => 'true',
                'PIXELITE_DELETION_ENABLED'      => 'true',
                'PIXELITE_EXPORT_ENABLED'        => 'true',
                'PIXELITE_CROSS_SESSION'         => 'true',
                'PIXELITE_BEHAVIORAL'            => 'true',
                'PIXELITE_COLLECT_SCREEN'        => 'true',
            ],
            'kvkk' => [
                'PIXELITE_COMPLIANCE_MODE'       => 'kvkk',
                'PIXELITE_CONSENT_REQUIRED'      => 'true',
                'PIXELITE_CONSENT_DEFAULT'       => 'denied',
                'PIXELITE_IP_ANONYMIZATION'      => 'partial',
                'PIXELITE_RETENTION_ENABLED'     => 'true',
                'PIXELITE_RETENTION_RAW_HOURS'   => '24',
                'PIXELITE_RETENTION_VISITS_DAYS' => '730',
                'PIXELITE_OPT_OUT_ENABLED'       => 'true',
                'PIXELITE_DELETION_ENABLED'      => 'true',
                'PIXELITE_EXPORT_ENABLED'        => 'true',
                'PIXELITE_CROSS_SESSION'         => 'false',
                'PIXELITE_BEHAVIORAL'            => 'false',
                'PIXELITE_COLLECT_SCREEN'        => 'false',
            ],
            'multi' => [
                'PIXELITE_COMPLIANCE_MODE'       => 'multi',
                'PIXELITE_CONSENT_REQUIRED'      => 'true',
                'PIXELITE_CONSENT_DEFAULT'       => 'denied',
                'PIXELITE_IP_ANONYMIZATION'      => 'partial',
                'PIXELITE_RETENTION_ENABLED'     => 'true',
                'PIXELITE_RETENTION_RAW_HOURS'   => '24',
                'PIXELITE_RETENTION_VISITS_DAYS' => '365',
                'PIXELITE_OPT_OUT_ENABLED'       => 'true',
                'PIXELITE_DELETION_ENABLED'      => 'true',
                'PIXELITE_EXPORT_ENABLED'        => 'true',
                'PIXELITE_CROSS_SESSION'         => 'false',
                'PIXELITE_BEHAVIORAL'            => 'false',
                'PIXELITE_COLLECT_SCREEN'        => 'false',
            ],
            default => [ // none
                'PIXELITE_COMPLIANCE_MODE'       => 'none',
                'PIXELITE_CONSENT_REQUIRED'      => 'false',
                'PIXELITE_CONSENT_DEFAULT'       => 'granted',
                'PIXELITE_IP_ANONYMIZATION'      => 'none',
                'PIXELITE_RETENTION_ENABLED'     => 'false',
                'PIXELITE_RETENTION_RAW_HOURS'   => '24',
                'PIXELITE_RETENTION_VISITS_DAYS' => '0',
                'PIXELITE_OPT_OUT_ENABLED'       => 'false',
                'PIXELITE_DELETION_ENABLED'      => 'true',
                'PIXELITE_EXPORT_ENABLED'        => 'true',
                'PIXELITE_CROSS_SESSION'         => 'true',
                'PIXELITE_BEHAVIORAL'            => 'true',
                'PIXELITE_COLLECT_SCREEN'        => 'true',
            ],
        };

        return array_merge($compliance, $cookieDefaults, $idDefaults);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Output helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function renderHeader(): void
    {
        $this->line('');
        $this->line('  <fg=blue> ____  _          _ _ _       </>');
        $this->line('  <fg=blue>|  _ \(_)_  _____| (_) |_ ___ </>');
        $this->line('  <fg=blue>| |_) | \ \/ / _ \ | | | __/ _ \</>');
        $this->line('  <fg=blue>|  __/| |>  <  __/ | | | ||  __/</>');
        $this->line('  <fg=blue>|_|   |_/_/\_\___|_|_|\__\___/</>');
        $this->line('');
        $this->line('  <fg=cyan>Privacy Compliance + Identifier Setup Wizard</>');
        $this->line('  Configure GDPR · CCPA · KVKK · UUID/ULID/Integer IDs interactively.');
        $this->line('');
    }

    private function renderSettingsTable(string $mode, array $s, string $processing): void
    {
        $label = self::MODES[$mode]['label'];
        $this->line('');
        $this->line("  <fg=green>Settings for {$label}:</>");
        $this->line('');

        $yn          = fn ($v) => $v === 'true' ? '<fg=green>Yes</>' : 'No';
        $teamLabel   = $s['PIXELITE_TEAM_ID_LABEL'] ?? 'team_id';
        $customLabel = $s['PIXELITE_CUSTOM_ID_LABEL'] ?? 'custom_id';

        $retentionValue = $s['PIXELITE_RETENTION_ENABLED'] === 'true'
            ? $s['PIXELITE_RETENTION_VISITS_DAYS'].' days'
            : 'Disabled';

        $geoValue = ($s['PIXELITE_COLLECT_GEO'] ?? 'false') === 'true'
            ? '<fg=green>Enabled</> ('.(basename($s['PIXELITE_GEO_DB_PATH'] ?? '') ?: '?').')'
            : 'Disabled — set PIXELITE_GEO_DB_PATH to enable';

        $rows = [
            ['Processing strategy',       ucfirst($processing).' job'],
            ['GeoIP collection',           $geoValue],
            ['Consent required',           $yn($s['PIXELITE_CONSENT_REQUIRED'])],
            ['Default (no consent)',        ucfirst($s['PIXELITE_CONSENT_DEFAULT'])],
            ['IP anonymisation',            ucfirst($s['PIXELITE_IP_ANONYMIZATION'])],
            ['Data retention',              $retentionValue],
            ['User opt-out',                $yn($s['PIXELITE_OPT_OUT_ENABLED'])],
            ['Data deletion (Art.17)',       $yn($s['PIXELITE_DELETION_ENABLED'])],
            ['Data export (Art.20)',         $yn($s['PIXELITE_EXPORT_ENABLED'])],
            ['Cross-session profiling',     $yn($s['PIXELITE_CROSS_SESSION'])],
            ['Behavioural tracking',        $yn($s['PIXELITE_BEHAVIORAL'])],
            ['Screen dimensions',           $s['PIXELITE_COLLECT_SCREEN'] === 'true' ? 'Collected' : 'Not collected'],
            ['user_id format',              $s['PIXELITE_USER_ID_FORMAT']],
            ["Track {$teamLabel}",          $s['PIXELITE_TRACK_TEAM_ID'] === 'true'
                ? "<fg=green>Yes</> ({$s['PIXELITE_TEAM_ID_FORMAT']})"
                : 'No'],
            ["Track {$customLabel}",        $s['PIXELITE_TRACK_CUSTOM_ID'] === 'true'
                ? "<fg=green>Yes</> ({$s['PIXELITE_CUSTOM_ID_FORMAT']})"
                : 'No'],
        ];

        if ($s['PIXELITE_CONSENT_REQUIRED'] === 'true') {
            $rows[] = ['Consent cookie', $s['PIXELITE_CONSENT_COOKIE']];
        }
        if ($s['PIXELITE_OPT_OUT_ENABLED'] === 'true') {
            $rows[] = ['Opt-out cookie', $s['PIXELITE_OPT_OUT_COOKIE']];
        }

        $this->table(['Setting', 'Value'], $rows);
    }

    private function renderNextSteps(string $mode, array $settings, string $processing): void
    {
        $this->line('');
        $this->line('  <fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('  <fg=green>  Setup complete!</>');
        $this->line('  <fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('');
        $this->line('  <fg=cyan>Next steps:</>');
        $this->line('');
        $this->line('  1. Register the middleware in your routes:');
        $this->line("     <fg=yellow>Route::middleware('pixelite.visit')->group(fn () => ...);</>");
        $this->line('');
        $this->line('  2. Include the tracking script in your Blade layout:');
        $this->line("     <fg=yellow><script src=\"/js/pixelite/pixelite.min.js\"></script></>");
        $this->line("     <fg=yellow><script>pixelite.init('{{ session(\"pixelite_trace_id\") }}')</script></>");
        $this->line('');

        if ($processing === 'daemon') {
            $this->line('  3. Add the daemon to Supervisor (/etc/supervisor/conf.d/pixelite.conf):');
            $this->line('');
            $this->line('     <fg=yellow>[program:pixelite-daemon]</>');
            $this->line('     <fg=yellow>command=php /var/www/artisan pixelite:daemon --batch-size=200</>');
            $this->line('     <fg=yellow>numprocs=2</>');
            $this->line('     <fg=yellow>autostart=true</>');
            $this->line('     <fg=yellow>autorestart=true</>');
            $this->line('     <fg=yellow>stopwaitsecs=30</>');
            $this->line('');
            $this->line('     Then: <fg=yellow>supervisorctl reread && supervisorctl update && supervisorctl start pixelite-daemon:*</>');
        } else {
            $this->line('  3. Schedule visit processing in App\\Console\\Kernel:');
            $this->line("     <fg=yellow>\$schedule->job(new \\Boralp\\Pixelite\\Jobs\\ProcessVisitRaw(200))->everyMinute();</>");
        }

        if ($mode !== 'none') {
            $this->line('');
            $this->line('  4. Schedule automatic data purge:');
            $this->line("     <fg=yellow>\$schedule->command('pixelite:purge-data --force')->daily();</>");
        }

        if (($settings['PIXELITE_COLLECT_GEO'] ?? 'false') === 'false') {
            $this->line('');
            $this->line('  <fg=yellow>⚠  GeoIP is disabled.</> When you have the database:');
            $this->line('     <fg=yellow>PIXELITE_GEO_DB_PATH=/path/to/GeoLite2-City.mmdb</>');
            $this->line('     <fg=yellow>PIXELITE_COLLECT_GEO=true</>');
        }

        if ($settings['PIXELITE_USER_ID_FORMAT'] !== 'integer') {
            $fmt = strtoupper($settings['PIXELITE_USER_ID_FORMAT']);
            $this->line('');
            $this->line("  <fg=yellow>Note:</> user_id format is <fg=cyan>{$fmt}</>. Ensure your users table uses the same PK type.");
        }

        $this->line('');
        $this->line('  <fg=cyan>Privacy commands:</>');
        $this->line('    pixelite:purge-data                 Delete expired records');
        $this->line('    pixelite:delete-user-data {id}      Right to erasure (Art.17)');
        $this->line('    pixelite:export-user-data {id}      Data portability (Art.20)');
        $this->line('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // .env writer
    // ─────────────────────────────────────────────────────────────────────────

    private function writeEnvVariables(array $settings): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->warn('.env file not found. Add these variables manually:');
            foreach ($settings as $key => $value) {
                $this->line("  {$key}={$value}");
            }

            return;
        }

        $content  = file_get_contents($envPath);
        $appended = [];

        foreach ($settings as $key => $value) {
            if (preg_match("/^{$key}=.*/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                $appended[$key] = $value;
            }
        }

        if (! empty($appended)) {
            $content = rtrim($content);
            if (! str_contains($content, '# Pixelite')) {
                $content .= "\n\n# Pixelite Privacy Settings";
            }
            foreach ($appended as $key => $value) {
                $content .= "\n{$key}={$value}";
            }
            $content .= "\n";
        }

        file_put_contents($envPath, $content);
    }
}

<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'pixelite:install
        {--mode=              : Skip the compliance wizard and apply a preset (gdpr, ccpa, kvkk, multi, none)}
        {--user-id-format=    : User ID format: integer (default) | uuid | ulid}
        {--team-id-format=    : Team ID format: integer (default) | uuid | ulid}
        {--team-id-label=     : Name shown for team_id in reports (e.g. organization_id)}
        {--custom-id-label=   : Name of your custom identifier (e.g. shop_id)}
        {--custom-id-format=  : Custom ID format: string (default) | uuid | ulid | integer}
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

        // ── Step 1: Compliance preset ─────────────────────────────────────────

        $mode = $this->option('mode');

        if ($mode !== null && ! array_key_exists($mode, self::MODES)) {
            $this->error("Unknown mode \"{$mode}\". Valid options: ".implode(', ', array_keys(self::MODES)));

            return self::FAILURE;
        }

        if ($mode === null) {
            $mode = $this->promptForMode();
        }

        $settings = $this->presetFor($mode);

        // ── Step 2: Identifier formats (always asked) ─────────────────────────

        $settings = $this->applyIdFormats($settings);

        // ── Step 3: Summary + optional compliance customisation ───────────────

        $this->renderSettingsTable($mode, $settings);

        if ($this->confirm('Would you like to customise any compliance settings?', false)) {
            $settings = $this->runComplianceCustomisation($settings);
        }

        // ── Step 4: Write .env ────────────────────────────────────────────────

        $this->writeEnvVariables($settings);
        $this->line('');
        $this->line('  <fg=green>✓</> Environment variables written.');

        // ── Step 5: Publish assets / migrations ───────────────────────────────

        if (! $this->option('no-publish')) {
            $this->line('');
            $this->runPublishingSteps();
        }

        $this->renderNextSteps($mode, $settings);

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

        // ── user_id ──────────────────────────────────────────────────────────
        $settings['PIXELITE_USER_ID_FORMAT'] = $this->pickIdFormat(
            'user_id',
            'What format are your user IDs? (auth()->id())',
            $this->option('user-id-format') ?? $settings['PIXELITE_USER_ID_FORMAT'],
            self::ID_FORMATS
        );

        // ── team_id ──────────────────────────────────────────────────────────
        $trackTeam = $this->confirm('Track a team / organisation ID with each visit?', $settings['PIXELITE_TRACK_TEAM_ID'] === 'true');
        $settings['PIXELITE_TRACK_TEAM_ID'] = $trackTeam ? 'true' : 'false';

        if ($trackTeam) {
            $teamLabel = $this->option('team-id-label')
                ?? $this->ask('What is this team identifier called in your app?', $settings['PIXELITE_TEAM_ID_LABEL']);
            $settings['PIXELITE_TEAM_ID_LABEL'] = $this->sanitizeLabel($teamLabel, 'team_id');

            $settings['PIXELITE_TEAM_ID_FORMAT'] = $this->pickIdFormat(
                $settings['PIXELITE_TEAM_ID_LABEL'],
                "What format is {$settings['PIXELITE_TEAM_ID_LABEL']}?",
                $this->option('team-id-format') ?? $settings['PIXELITE_TEAM_ID_FORMAT'],
                self::ID_FORMATS
            );

            $settings['PIXELITE_TEAM_ID_RESOLVER'] = $this->ask(
                "How is {$settings['PIXELITE_TEAM_ID_LABEL']} resolved? (user.team_id | session.key | request.key | header.name)",
                $settings['PIXELITE_TEAM_ID_RESOLVER']
            );
        }

        // ── custom_id ─────────────────────────────────────────────────────────
        $trackCustom = $this->confirm(
            'Track a custom identifier? (e.g. shop_id, account_id, tenant_id)',
            $settings['PIXELITE_TRACK_CUSTOM_ID'] === 'true'
        );
        $settings['PIXELITE_TRACK_CUSTOM_ID'] = $trackCustom ? 'true' : 'false';

        if ($trackCustom) {
            $customLabel = $this->option('custom-id-label')
                ?? $this->ask('What should this identifier be called? (shown in reports and the DB label column)', $settings['PIXELITE_CUSTOM_ID_LABEL']);
            $settings['PIXELITE_CUSTOM_ID_LABEL'] = $this->sanitizeLabel($customLabel, 'custom_id');

            $settings['PIXELITE_CUSTOM_ID_FORMAT'] = $this->pickIdFormat(
                $settings['PIXELITE_CUSTOM_ID_LABEL'],
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

    /** Present a choice list of ID formats, defaulting to the current value. */
    private function pickIdFormat(string $fieldName, string $question, string $current, array $formats): string
    {
        $choices = array_values($formats);
        $keys    = array_keys($formats);
        $default = array_search($current, $keys, true);
        $default = ($default !== false) ? $default : 0;

        $selected = $this->choice($question, $choices, $default);
        $index    = array_search($selected, $choices, true);

        return $keys[$index];
    }

    /** Sanitize a user-provided label to snake_case, max 64 chars. */
    private function sanitizeLabel(mixed $value, string $fallback): string
    {
        $label = preg_replace('/[^a-z0-9_]/i', '_', (string) ($value ?: $fallback));
        $label = strtolower(trim($label, '_'));

        return $label !== '' ? substr($label, 0, 64) : $fallback;
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
        $currentAnonIndex = match ($settings['PIXELITE_IP_ANONYMIZATION']) {
            'partial' => 1,
            'full'    => 2,
            default   => 0,
        };
        $anonChoice = $this->choice('IP address anonymisation level?', $anonOptions, $currentAnonIndex);
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
            'PIXELITE_USER_ID_FORMAT'   => 'integer',
            'PIXELITE_TRACK_TEAM_ID'    => 'false',
            'PIXELITE_TEAM_ID_LABEL'    => 'team_id',
            'PIXELITE_TEAM_ID_FORMAT'   => 'integer',
            'PIXELITE_TEAM_ID_RESOLVER' => 'user.team_id',
            'PIXELITE_TRACK_CUSTOM_ID'  => 'false',
            'PIXELITE_CUSTOM_ID_LABEL'  => 'custom_id',
            'PIXELITE_CUSTOM_ID_FORMAT' => 'string',
            'PIXELITE_CUSTOM_ID_RESOLVER' => 'user.custom_id',
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

        // ID defaults always follow compliance — user overrides them in Step 2
        return array_merge($compliance, $idDefaults);
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

    private function renderSettingsTable(string $mode, array $s): void
    {
        $label = self::MODES[$mode]['label'];
        $this->line('');
        $this->line("  <fg=green>Settings for {$label}:</>");
        $this->line('');

        $yn = fn ($v) => $v === 'true' ? '<fg=green>Yes</>' : 'No';

        $retentionValue = $s['PIXELITE_RETENTION_ENABLED'] === 'true'
            ? $s['PIXELITE_RETENTION_VISITS_DAYS'].' days'
            : 'Disabled';

        $teamLabel   = $s['PIXELITE_TEAM_ID_LABEL'] ?? 'team_id';
        $customLabel = $s['PIXELITE_CUSTOM_ID_LABEL'] ?? 'custom_id';

        $this->table(
            ['Setting', 'Value'],
            [
                ['Consent required',          $yn($s['PIXELITE_CONSENT_REQUIRED'])],
                ['Default (no consent)',       ucfirst($s['PIXELITE_CONSENT_DEFAULT'])],
                ['IP anonymisation',           ucfirst($s['PIXELITE_IP_ANONYMIZATION'])],
                ['Data retention',             $retentionValue],
                ['User opt-out',               $yn($s['PIXELITE_OPT_OUT_ENABLED'])],
                ['Data deletion (Art.17)',      $yn($s['PIXELITE_DELETION_ENABLED'])],
                ['Data export (Art.20)',        $yn($s['PIXELITE_EXPORT_ENABLED'])],
                ['Cross-session profiling',    $yn($s['PIXELITE_CROSS_SESSION'])],
                ['Behavioural tracking',       $yn($s['PIXELITE_BEHAVIORAL'])],
                ['Screen dimensions',          $s['PIXELITE_COLLECT_SCREEN'] === 'true' ? 'Collected' : 'Not collected'],
                ['user_id format',             $s['PIXELITE_USER_ID_FORMAT']],
                ["Track {$teamLabel}",         $s['PIXELITE_TRACK_TEAM_ID'] === 'true'
                    ? "<fg=green>Yes</> ({$s['PIXELITE_TEAM_ID_FORMAT']})"
                    : 'No'],
                ["Track {$customLabel}",       $s['PIXELITE_TRACK_CUSTOM_ID'] === 'true'
                    ? "<fg=green>Yes</> ({$s['PIXELITE_CUSTOM_ID_FORMAT']})"
                    : 'No'],
            ]
        );
    }

    private function renderNextSteps(string $mode, array $settings): void
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
        $this->line('  2. Set your GeoIP database path (.env):');
        $this->line('     <fg=yellow>PIXELITE_GEO_DB_PATH=/path/to/GeoLite2-City.mmdb</>');
        $this->line('     Free download: dev.maxmind.com (GeoLite2 account required)');
        $this->line('');
        $this->line('  3. Include the tracking script in your Blade layout:');
        $this->line("     <fg=yellow><script src=\"/js/pixelite/pixelite.min.js\"></script></>");
        $this->line("     <fg=yellow><script>pixelite.init('{{ session(\"pixelite_trace_id\") }}')</script></>");

        if ($mode !== 'none') {
            $this->line('');
            $this->line('  4. Schedule automatic data purge:');
            $this->line("     <fg=yellow>\$schedule->command('pixelite:purge-data')->daily();</>");
        }

        $this->line('');
        $this->line('  <fg=cyan>Privacy commands:</>');
        $this->line('    pixelite:purge-data                 Delete expired records');
        $this->line('    pixelite:delete-user-data {id}      Right to erasure (Art.17)');
        $this->line('    pixelite:export-user-data {id}      Data portability (Art.20)');

        if ($settings['PIXELITE_USER_ID_FORMAT'] !== 'integer') {
            $fmt = strtoupper($settings['PIXELITE_USER_ID_FORMAT']);
            $this->line('');
            $this->line("  <fg=yellow>Note:</> user_id format is <fg=cyan>{$fmt}</>.");
            $this->line('  Make sure your users table uses the same format as its primary key.');
        }

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

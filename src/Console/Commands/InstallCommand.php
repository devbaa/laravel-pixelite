<?php

namespace Boralp\Pixelite\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'pixelite:install
        {--mode= : Skip the wizard and apply a preset directly (gdpr, ccpa, kvkk, multi, none)}
        {--no-publish : Skip asset / migration publishing prompts}';

    protected $description = 'Interactive privacy compliance setup wizard (GDPR, CCPA, KVKK)';

    /** Human-readable metadata for each compliance mode. */
    private const MODES = [
        'none'  => ['label' => 'None',                 'desc' => 'No restrictions — collect all data'],
        'gdpr'  => ['label' => 'GDPR',                 'desc' => 'EU General Data Protection Regulation'],
        'ccpa'  => ['label' => 'CCPA',                 'desc' => 'California Consumer Privacy Act'],
        'kvkk'  => ['label' => 'KVKK',                 'desc' => 'Turkish Personal Data Protection Law'],
        'multi' => ['label' => 'Multiple regulations', 'desc' => 'Apply strictest settings (GDPR + CCPA + KVKK)'],
    ];

    public function handle(): int
    {
        $this->renderHeader();

        $mode = $this->option('mode');

        if ($mode !== null && ! array_key_exists($mode, self::MODES)) {
            $this->error("Unknown mode "{$mode}". Valid options: ".implode(', ', array_keys(self::MODES)));

            return self::FAILURE;
        }

        if ($mode === null) {
            $mode = $this->promptForMode();
        }

        $settings = $this->presetFor($mode);

        $this->renderSettingsTable($mode, $settings);

        if ($this->confirm('Would you like to customise any of these settings?', false)) {
            $settings = $this->runCustomisation($settings);
        }

        $this->writeEnvVariables($settings);
        $this->line('');
        $this->line('  <fg=green>✓</> Environment variables written.');

        if (! $this->option('no-publish')) {
            $this->line('');
            $this->runPublishingSteps();
        }

        $this->renderNextSteps($mode);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Wizard steps
    // -------------------------------------------------------------------------

    private function promptForMode(): string
    {
        $choices = array_map(
            fn ($k, $v) => "{$v['label']} — {$v['desc']}",
            array_keys(self::MODES),
            self::MODES
        );

        $selected = $this->choice('Which privacy regulation applies to your application?', $choices, 0);

        $index = array_search($selected, $choices, true);

        return array_keys(self::MODES)[$index];
    }

    private function runCustomisation(array $settings): array
    {
        $this->line('');
        $this->line('  <fg=cyan>Customising settings</> — press Enter to keep the shown default.');
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

        // --- Team ID ---
        $trackTeam = $this->confirm(
            'Track a team / organisation ID with each visit? (multi-tenancy)',
            $settings['PIXELITE_TRACK_TEAM_ID'] === 'true'
        );
        $settings['PIXELITE_TRACK_TEAM_ID'] = $trackTeam ? 'true' : 'false';

        if ($trackTeam) {
            $settings['PIXELITE_TEAM_ID_RESOLVER'] = $this->ask(
                'How is team_id resolved? (user.team_id | session.key | request.key | header.name)',
                $settings['PIXELITE_TEAM_ID_RESOLVER']
            );
        }

        // --- Custom ID ---
        $trackCustom = $this->confirm(
            'Track a custom string identifier? (e.g. shop_id, account_id, tenant_id)',
            $settings['PIXELITE_TRACK_CUSTOM_ID'] === 'true'
        );
        $settings['PIXELITE_TRACK_CUSTOM_ID'] = $trackCustom ? 'true' : 'false';

        if ($trackCustom) {
            $settings['PIXELITE_CUSTOM_ID_LABEL'] = $this->ask(
                'What is the human-readable name for this identifier? (e.g. shop_id)',
                $settings['PIXELITE_CUSTOM_ID_LABEL']
            );
            $settings['PIXELITE_CUSTOM_ID_RESOLVER'] = $this->ask(
                'How is it resolved? (user.shop_id | session.key | request.key | header.name)',
                $settings['PIXELITE_CUSTOM_ID_RESOLVER']
            );
        }

        return $settings;
    }

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

    // -------------------------------------------------------------------------
    // Presets
    // -------------------------------------------------------------------------

    private function presetFor(string $mode): array
    {
        return match ($mode) {
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
                'PIXELITE_TRACK_TEAM_ID'         => 'false',
                'PIXELITE_TEAM_ID_RESOLVER'      => 'user.team_id',
                'PIXELITE_TRACK_CUSTOM_ID'       => 'false',
                'PIXELITE_CUSTOM_ID_LABEL'       => 'custom_id',
                'PIXELITE_CUSTOM_ID_RESOLVER'    => 'user.custom_id',
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
                'PIXELITE_TRACK_TEAM_ID'         => 'false',
                'PIXELITE_TEAM_ID_RESOLVER'      => 'user.team_id',
                'PIXELITE_TRACK_CUSTOM_ID'       => 'false',
                'PIXELITE_CUSTOM_ID_LABEL'       => 'custom_id',
                'PIXELITE_CUSTOM_ID_RESOLVER'    => 'user.custom_id',
            ],
            'kvkk' => [
                'PIXELITE_COMPLIANCE_MODE'       => 'kvkk',
                'PIXELITE_CONSENT_REQUIRED'      => 'true',
                'PIXELITE_CONSENT_DEFAULT'       => 'denied',
                'PIXELITE_IP_ANONYMIZATION'      => 'partial',
                'PIXELITE_RETENTION_ENABLED'     => 'true',
                'PIXELITE_RETENTION_RAW_HOURS'   => '24',
                'PIXELITE_RETENTION_VISITS_DAYS' => '730',  // 2 years per KVKK guidance
                'PIXELITE_OPT_OUT_ENABLED'       => 'true',
                'PIXELITE_DELETION_ENABLED'      => 'true',
                'PIXELITE_EXPORT_ENABLED'        => 'true',
                'PIXELITE_CROSS_SESSION'         => 'false',
                'PIXELITE_BEHAVIORAL'            => 'false',
                'PIXELITE_COLLECT_SCREEN'        => 'false',
                'PIXELITE_TRACK_TEAM_ID'         => 'false',
                'PIXELITE_TEAM_ID_RESOLVER'      => 'user.team_id',
                'PIXELITE_TRACK_CUSTOM_ID'       => 'false',
                'PIXELITE_CUSTOM_ID_LABEL'       => 'custom_id',
                'PIXELITE_CUSTOM_ID_RESOLVER'    => 'user.custom_id',
            ],
            'multi' => [
                // Strictest intersection of GDPR + CCPA + KVKK
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
                'PIXELITE_TRACK_TEAM_ID'         => 'false',
                'PIXELITE_TEAM_ID_RESOLVER'      => 'user.team_id',
                'PIXELITE_TRACK_CUSTOM_ID'       => 'false',
                'PIXELITE_CUSTOM_ID_LABEL'       => 'custom_id',
                'PIXELITE_CUSTOM_ID_RESOLVER'    => 'user.custom_id',
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
                'PIXELITE_TRACK_TEAM_ID'         => 'false',
                'PIXELITE_TEAM_ID_RESOLVER'      => 'user.team_id',
                'PIXELITE_TRACK_CUSTOM_ID'       => 'false',
                'PIXELITE_CUSTOM_ID_LABEL'       => 'custom_id',
                'PIXELITE_CUSTOM_ID_RESOLVER'    => 'user.custom_id',
            ],
        };
    }

    // -------------------------------------------------------------------------
    // Output helpers
    // -------------------------------------------------------------------------

    private function renderHeader(): void
    {
        $this->line('');
        $this->line('  <fg=blue> ____  _          _ _ _       </>');
        $this->line('  <fg=blue>|  _ \(_)_  _____| (_) |_ ___ </>');
        $this->line('  <fg=blue>| |_) | \ \/ / _ \ | | | __/ _ \</>');
        $this->line('  <fg=blue>|  __/| |>  <  __/ | | | ||  __/</>');
        $this->line('  <fg=blue>|_|   |_/_/\_\___|_|_|\__\___/</>');
        $this->line('');
        $this->line('  <fg=cyan>Privacy Compliance Setup Wizard</>');
        $this->line('  Configure GDPR · CCPA · KVKK settings interactively.');
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

        $customIdLabel = ! empty($s['PIXELITE_CUSTOM_ID_LABEL']) ? $s['PIXELITE_CUSTOM_ID_LABEL'] : 'custom_id';

        $this->table(
            ['Setting', 'Value'],
            [
                ['Consent required',          $yn($s['PIXELITE_CONSENT_REQUIRED'])],
                ['Default (no consent)',       ucfirst($s['PIXELITE_CONSENT_DEFAULT'])],
                ['IP anonymisation',          ucfirst($s['PIXELITE_IP_ANONYMIZATION'])],
                ['Data retention',            $retentionValue],
                ['User opt-out',              $yn($s['PIXELITE_OPT_OUT_ENABLED'])],
                ['Data deletion (Art.17)',     $yn($s['PIXELITE_DELETION_ENABLED'])],
                ['Data export (Art.20)',       $yn($s['PIXELITE_EXPORT_ENABLED'])],
                ['Cross-session profiling',   $yn($s['PIXELITE_CROSS_SESSION'])],
                ['Behavioural tracking',      $yn($s['PIXELITE_BEHAVIORAL'])],
                ['Screen dimensions',         $s['PIXELITE_COLLECT_SCREEN'] === 'true' ? 'Collected' : 'Not collected'],
                ['Track team_id',             $yn($s['PIXELITE_TRACK_TEAM_ID'])],
                ["Track {$customIdLabel}",    $yn($s['PIXELITE_TRACK_CUSTOM_ID'])],
            ]
        );
    }

    private function renderNextSteps(string $mode): void
    {
        $this->line('');
        $this->line('  <fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('  <fg=green>  Setup complete!</>');
        $this->line('  <fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('');
        $this->line('  <fg=cyan>Next steps:</>');
        $this->line('');
        $this->line("  1. Register the middleware in your routes or Kernel:");
        $this->line("     <fg=yellow>Route::middleware('pixelite.visit')->group(fn () => ...);</>");
        $this->line('');
        $this->line("  2. Set your GeoIP database path (.env):");
        $this->line("     <fg=yellow>PIXELITE_GEO_DB_PATH=/path/to/GeoLite2-City.mmdb</>");
        $this->line("     Free download: dev.maxmind.com (GeoLite2 account required)");
        $this->line('');
        $this->line("  3. Include the tracking script in your Blade layout:");
        $this->line('     <fg=yellow><script src="/js/pixelite/pixelite.min.js"></script></>');
        $this->line("     <fg=yellow><script>pixelite.init('{{ session(\"pixelite_trace_id\") }}')</script></>");

        if ($mode !== 'none') {
            $this->line('');
            $this->line("  4. Schedule automatic data purge in App\\Console\\Kernel:");
            $this->line("     <fg=yellow>\$schedule->command('pixelite:purge-data')->daily();</>");
        }

        $this->line('');
        $this->line('  <fg=cyan>Privacy commands:</>');
        $this->line('    pixelite:purge-data                 Delete expired records');
        $this->line('    pixelite:delete-user-data {id}      Right to erasure (Art.17)');
        $this->line('    pixelite:export-user-data {id}      Data portability (Art.20)');
        $this->line('');
    }

    // -------------------------------------------------------------------------
    // .env writer
    // -------------------------------------------------------------------------

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

        $content = file_get_contents($envPath);
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

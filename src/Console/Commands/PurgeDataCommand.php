<?php

namespace Boralp\Pixelite\Console\Commands;

use Boralp\Pixelite\Services\PrivacyService;
use Illuminate\Console\Command;

class PurgeDataCommand extends Command
{
    protected $signature = 'pixelite:purge-data
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete expired visit records according to the configured retention policy';

    public function __construct(private readonly PrivacyService $privacy)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('pixelite.retention.enabled', false)) {
            $this->warn('Data retention is disabled. Set PIXELITE_RETENTION_ENABLED=true to activate.');

            return self::SUCCESS;
        }

        $rawHours = config('pixelite.retention.raw_hours', 24);
        $visitsDays = config('pixelite.retention.visits_days', 365);

        $this->line("Retention policy: raw visits > {$rawHours}h  |  processed visits > {$visitsDays} days");

        if (! $this->option('force') && ! $this->confirm('Proceed with purge?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $result = $this->privacy->purgeOldData();

        $this->info(
            "✓ Purged {$result['raw_deleted']} raw record(s) and {$result['visits_deleted']} visit record(s)."
        );

        return self::SUCCESS;
    }
}

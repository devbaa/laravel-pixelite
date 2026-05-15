<?php

namespace Boralp\Pixelite\Console\Commands;

use Boralp\Pixelite\Services\PrivacyService;
use Illuminate\Console\Command;

class DeleteUserDataCommand extends Command
{
    protected $signature = 'pixelite:delete-user-data
        {identifier       : User ID or session ID whose data should be erased}
        {--type=user_id   : Identifier type: user_id | session_id}
        {--force          : Skip the confirmation prompt}';

    protected $description = 'Permanently erase all visit data for a user (GDPR Art.17 — Right to Erasure)';

    public function __construct(private readonly PrivacyService $privacy)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('pixelite.rights.deletion_enabled', true)) {
            $this->error('Data deletion is disabled. Set PIXELITE_DELETION_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $identifier = $this->argument('identifier');
        $type = $this->option('type');

        if (! in_array($type, ['user_id', 'session_id'], true)) {
            $this->error('Invalid --type value. Use: user_id  or  session_id');

            return self::FAILURE;
        }

        $this->warn("This will permanently delete ALL visit data for {$type}: {$identifier}");
        $this->warn('This action cannot be undone.');

        if (! $this->option('force') && ! $this->confirm('Are you sure?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $result = match ($type) {
            'session_id' => $this->privacy->deleteBySessionId($identifier),
            default      => $this->privacy->deleteByUserId((int) $identifier),
        };

        $this->info("✓ Deleted {$result['total']} record(s).");
        $this->info("  Raw: {$result['raw_deleted']}  |  Processed: {$result['visits_deleted']}");
        $this->info('  Erasure request logged to pixelite_dsr.');

        return self::SUCCESS;
    }
}

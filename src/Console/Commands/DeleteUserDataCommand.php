<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Console\Commands;

use Boralp\Pixelite\Services\PrivacyService;
use Illuminate\Console\Command;

class DeleteUserDataCommand extends Command
{
    protected $signature = 'pixelite:delete-user-data
        {identifier         : The identifier value to erase}
        {--type=user_id     : Identifier type: user_id | team_id | session_id | custom_id}
        {--force            : Skip the confirmation prompt}';

    protected $description = 'Permanently erase all visit data for a user, team, or custom ID (GDPR Art.17 — Right to Erasure)';

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

        $validTypes = ['user_id', 'team_id', 'session_id', 'custom_id'];
        if (! in_array($type, $validTypes, true)) {
            $this->error('Invalid --type value. Use: '.implode('  |  ', $validTypes));

            return self::FAILURE;
        }

        $label = $type === 'custom_id'
            ? config('pixelite.tracking.custom_id.label', 'custom_id')
            : $type;

        $this->warn("This will permanently delete ALL visit data for {$label}: {$identifier}");
        $this->warn('This action cannot be undone.');

        if (! $this->option('force') && ! $this->confirm('Are you sure?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $result = match ($type) {
            'team_id'    => $this->privacy->deleteByTeamId((int) $identifier),
            'session_id' => $this->privacy->deleteBySessionId($identifier),
            'custom_id'  => $this->privacy->deleteByCustomId($identifier),
            default      => $this->privacy->deleteByUserId((int) $identifier),
        };

        $this->info("✓ Deleted {$result['total']} record(s).");
        $this->info("  Raw: {$result['raw_deleted']}  |  Processed: {$result['visits_deleted']}");
        $this->info('  Erasure request logged to pixelite_dsr.');

        return self::SUCCESS;
    }
}

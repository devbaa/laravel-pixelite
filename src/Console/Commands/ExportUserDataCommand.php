<?php

namespace Boralp\Pixelite\Console\Commands;

use Boralp\Pixelite\Services\PrivacyService;
use Illuminate\Console\Command;

class ExportUserDataCommand extends Command
{
    protected $signature = 'pixelite:export-user-data
        {identifier  : User ID whose data should be exported}
        {--output=   : Write JSON to this file path instead of stdout}
        {--pretty    : Pretty-print the JSON output}';

    protected $description = 'Export all visit data for a user as JSON (GDPR Art.20 — Data Portability)';

    public function __construct(private readonly PrivacyService $privacy)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('pixelite.rights.export_enabled', true)) {
            $this->error('Data export is disabled. Set PIXELITE_EXPORT_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $userId = (int) $this->argument('identifier');
        $visits = $this->privacy->exportByUserId($userId);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $payload = json_encode([
            'export_date'   => now()->toISOString(),
            'user_id'       => $userId,
            'total_records' => count($visits),
            'visits'        => $visits,
        ], $flags);

        $outputPath = $this->option('output');

        if ($outputPath) {
            file_put_contents($outputPath, $payload);
            $this->info('✓ Exported '.count($visits)." record(s) to: {$outputPath}");
            $this->info('  Export request logged to pixelite_dsr.');
        } else {
            $this->output->writeln($payload);
        }

        return self::SUCCESS;
    }
}

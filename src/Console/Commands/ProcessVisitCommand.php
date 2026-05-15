<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Console\Commands;

use Boralp\Pixelite\Jobs\ProcessVisitRaw;
use Illuminate\Console\Command;

class ProcessVisitCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pixelite:process-visits {--batch-size=500 : Number of records to process per batch}';

    /**
     * The console command description.
     */
    protected $description = 'Process raw visit data and convert to normalized visits';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');

        $this->info("Processing raw visits with batch size: {$batchSize}");

        try {
            // Create and handle the job directly (synchronous execution)
            $job = new ProcessVisitRaw($batchSize);
            $job->handle();

            $this->info('Processing completed successfully!');
        } catch (\Exception $e) {
            $this->error('Processing failed: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return 1;
        }

        return 0;
    }
}

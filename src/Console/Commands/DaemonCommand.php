<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Console\Commands;

use Boralp\Pixelite\Models\VisitRaw;
use Boralp\Pixelite\Services\VisitProcessor;
use Illuminate\Console\Command;

/**
 * Long-running daemon that continuously drains the visit_raws table.
 *
 * Designed to be managed by Supervisor (multiple workers are safe — each
 * worker claims its batch with SELECT FOR UPDATE before deleting).
 *
 * Supervisor config example:
 *
 *   [program:pixelite-daemon]
 *   command=php /var/www/artisan pixelite:daemon --batch-size=200
 *   numprocs=2
 *   autostart=true
 *   autorestart=true
 *   stopwaitsecs=60
 *   stdout_logfile=/var/www/storage/logs/pixelite-daemon.log
 */
final class DaemonCommand extends Command
{
    protected $signature = 'pixelite:daemon
        {--batch-size=200  : Records to process per cycle}
        {--sleep=500       : Milliseconds to sleep when the queue is empty}
        {--max-memory=128  : Restart after exceeding this memory limit (MB)}
        {--once            : Process a single batch then exit (useful for testing)}';

    protected $description = 'Continuously process raw visit records (Supervisor-ready daemon)';

    private bool $shouldStop = false;

    public function handle(VisitProcessor $processor): int
    {
        $this->registerSignalHandlers();

        $batchSize = (int) $this->option('batch-size');
        $sleepMs   = (int) $this->option('sleep');
        $maxMb     = (int) $this->option('max-memory');
        $once      = (bool) $this->option('once');

        $this->line(sprintf(
            '[%s] Pixelite daemon started — pid %d, batch %d, max-memory %dMB',
            now()->toDateTimeString(),
            getmypid(),
            $batchSize,
            $maxMb,
        ));

        do {
            $this->dispatchSignals();

            if ($this->shouldStop) {
                break;
            }

            if (! VisitRaw::exists()) {
                usleep($sleepMs * 1000);
                continue;
            }

            $count = $processor->run($batchSize);

            if ($count === 0) {
                usleep($sleepMs * 1000);
            }

            if ($this->isOverMemoryLimit($maxMb)) {
                $this->line(sprintf(
                    '[%s] Memory limit reached (%dMB) — exiting for Supervisor restart.',
                    now()->toDateTimeString(),
                    $maxMb,
                ));
                break;
            }

        } while (! $once && ! $this->shouldStop);

        $processor->shutdown();

        $this->line('['.now()->toDateTimeString().'] Pixelite daemon stopped.');

        return self::SUCCESS;
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function (): void { $this->shouldStop = true; };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT,  $stop);
        pcntl_signal(SIGQUIT, $stop);
    }

    private function dispatchSignals(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_signal_dispatch();
        }
    }

    private function isOverMemoryLimit(int $maxMb): bool
    {
        return memory_get_usage(true) / 1024 / 1024 > $maxMb;
    }
}

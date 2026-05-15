<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Jobs;

use Boralp\Pixelite\Services\VisitProcessor;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue-friendly wrapper around VisitProcessor.
 * Dispatch this job to process raw visits asynchronously.
 *
 * For continuous high-throughput processing, prefer the daemon:
 *   php artisan pixelite:daemon
 */
final class ProcessVisitRaw implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $batchSize = 500)
    {
        $this->batchSize = max(1, min($batchSize, 1000));
    }

    public function handle(VisitProcessor $processor): void
    {
        try {
            $count = $processor->run($this->batchSize);
            Log::debug('Pixelite: processed batch', ['count' => $count]);
        } catch (Exception $e) {
            Log::error('Pixelite: ProcessVisitRaw job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            $processor->shutdown();
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('Pixelite: ProcessVisitRaw job failed permanently', [
            'error'      => $exception->getMessage(),
            'batch_size' => $this->batchSize,
        ]);
    }
}

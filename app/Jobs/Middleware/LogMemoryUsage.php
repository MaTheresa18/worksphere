<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class LogMemoryUsage
{
    /**
     * Process the job.
     *
     * @param  mixed  $job
     * @param  callable  $next
     */
    public function handle($job, Closure $next): void
    {
        $startMemory = memory_get_usage();

        $next($job);

        $endMemory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();

        $diff = $endMemory - $startMemory;

        Log::info(sprintf(
            '[JobMemory] %s: Used: %s MB, Peak: %s MB, Leak/Diff: %s MB',
            get_class($job),
            round($endMemory / 1024 / 1024, 2),
            round($peakMemory / 1024 / 1024, 2),
            round($diff / 1024 / 1024, 2)
        ));
    }
}

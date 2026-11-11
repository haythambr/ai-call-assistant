<?php

namespace App\Jobs;

use App\Models\Call;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCall implements ShouldQueue
{
    use Queueable;

    /**
     * Failures are stored on the call and retried from the dashboard,
     * so the queue itself does not retry.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Call $callRecord) {}

    public function handle(): void
    {
        $this->callRecord->process();
    }
}

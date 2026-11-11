<?php

namespace App\Jobs;

use App\Models\Call;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Demo mode only: stands in for the voice provider's webhook a few seconds
 * after an outbound call is "placed".
 */
class DeliverDemoOutcome implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Call $callRecord) {}

    public function handle(): void
    {
        $this->callRecord->completeDemoOutboundCall();
    }
}

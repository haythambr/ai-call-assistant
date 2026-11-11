<?php

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of a campaign: places the next call and queues the step after it.
 */
class RunCampaign implements ShouldQueue
{
    use Queueable;

    /**
     * A step that hits a passing problem (a busy database, say) is tried
     * again. It cannot dial twice: the person is marked as called in the
     * same step that places the call, and a retry then moves on.
     */
    public int $tries = 3;

    public array $backoff = [5, 30];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Campaign $campaign, public string $token) {}

    public function handle(): void
    {
        $this->campaign->runNextStep($this->token);
    }
}

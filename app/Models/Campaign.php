<?php

namespace App\Models;

use App\Jobs\RunCampaign;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A list of contacts the AI calls one after another for the same reason,
 * started right away or at a scheduled time.
 *
 * Runs as a chain of queued steps (RunCampaign), one call per step, so no
 * cron is needed. Each start or resume issues a new run token, and a step
 * with an old token does nothing, so pausing and resuming can never leave
 * two chains calling at once. Outside calling hours the campaign waits for
 * the next opening instead of skipping anyone.
 */
class Campaign extends Model
{
    use BelongsToUser;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'name',
        'purpose',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'run_token',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /* ------------------------------------------------------------------
     | Launching
     | ------------------------------------------------------------------ */

    /**
     * Create a campaign for the given contacts. People who can never be called
     * (no consent, or asked not to be called) are listed as skipped up front.
     *
     * @param  Collection<int, Contact>  $contacts
     */
    public static function launch(User $user, string $name, string $purpose, Collection $contacts, ?CarbonImmutable $scheduledAt = null): self
    {
        $campaign = $user->campaigns()->create([
            'name' => Str::limit(trim($name), 120, ''),
            'purpose' => Str::limit(trim($purpose), 180, ''),
            'status' => self::STATUS_SCHEDULED,
            // Stored in the app timezone: Eloquent saves the wall-clock time as given.
            'scheduled_at' => $scheduledAt?->isFuture() ? $scheduledAt->setTimezone(config('app.timezone')) : null,
        ]);

        $now = now();
        $campaign->recipients()->insert($contacts->unique('id')->map(fn (Contact $contact) => [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => $contact->permissionBlocker() ? CampaignRecipient::STATUS_SKIPPED : CampaignRecipient::STATUS_PENDING,
            'reason' => $contact->permissionBlocker(),
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());

        if ($campaign->scheduled_at) {
            $campaign->update(['run_token' => Str::random(32)]);
            RunCampaign::dispatch($campaign, $campaign->run_token)->delay($campaign->scheduled_at);
        } else {
            $campaign->start();
        }

        return $campaign;
    }

    /**
     * Start (or resume) calling now, with a fresh run token.
     */
    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => $this->started_at ?? now(),
            'run_token' => Str::random(32),
        ]);

        RunCampaign::dispatch($this, $this->run_token);
    }

    /* ------------------------------------------------------------------
     | Running
     | ------------------------------------------------------------------ */

    /**
     * One step: call the next person, then queue the step after it.
     */
    public function runNextStep(string $token): void
    {
        $this->refresh();

        if ($token !== $this->run_token) {
            return; // paused, resumed or cancelled since this step was queued
        }

        if ($this->status === self::STATUS_SCHEDULED) {
            $this->scheduled_at?->isFuture()
                ? RunCampaign::dispatch($this, $token)->delay($this->scheduled_at)
                : $this->start();

            return;
        }

        if ($this->status !== self::STATUS_RUNNING) {
            return;
        }

        $recipient = $this->recipients()->where('status', CampaignRecipient::STATUS_PENDING)->orderBy('id')->with('contact')->first();

        if (! $recipient) {
            $this->update(['status' => self::STATUS_COMPLETED, 'completed_at' => now(), 'run_token' => null]);

            return;
        }

        if (Contact::callingHoursBlocker()) {
            RunCampaign::dispatch($this, $token)->delay(Contact::nextCallingTime());

            return;
        }

        if ($reason = $recipient->contact->callBlocker()) {
            $recipient->update(['status' => CampaignRecipient::STATUS_SKIPPED, 'reason' => $reason]);
            RunCampaign::dispatch($this, $token);

            return;
        }

        // Marked first, so a retried step can never ring the same person twice.
        $recipient->update(['status' => CampaignRecipient::STATUS_CALLED]);
        $call = Call::dial($recipient->contact, $this->purpose, $this);
        $recipient->update(['call_id' => $call->id]);

        RunCampaign::dispatch($this, $token)->delay(now()->addSeconds(config('call_assistant.outbound.seconds_between_calls')));
    }

    /**
     * Restart a running campaign whose chain of steps was lost (the queue
     * worker was down, or a step failed for good). Safe to call often: it
     * only acts when nothing has happened for well over one call gap.
     */
    public function resumeIfStalled(): bool
    {
        if ($this->status !== self::STATUS_RUNNING || Contact::callingHoursBlocker()) {
            return false;
        }

        $quietFor = max(120, 3 * (int) config('call_assistant.outbound.seconds_between_calls'));
        $lastActivity = collect([$this->updated_at, $this->recipients()->max('updated_at')])
            ->filter()
            ->map(fn ($time) => CarbonImmutable::parse($time))
            ->max();

        if ($lastActivity?->gt(now()->subSeconds($quietFor)) || $this->recipients()->where('status', CampaignRecipient::STATUS_PENDING)->doesntExist()) {
            return false;
        }

        $this->start();

        return true;
    }

    /* ------------------------------------------------------------------
     | Controls
     | ------------------------------------------------------------------ */

    public function canPause(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function canResume(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function canStartNow(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_RUNNING, self::STATUS_PAUSED], true);
    }

    public function pause(): bool
    {
        if (! $this->canPause()) {
            return false;
        }

        $this->update(['status' => self::STATUS_PAUSED, 'run_token' => null]);

        return true;
    }

    public function resume(): bool
    {
        if (! $this->canResume()) {
            return false;
        }

        $this->start();

        return true;
    }

    public function startNow(): bool
    {
        if (! $this->canStartNow()) {
            return false;
        }

        $this->update(['scheduled_at' => null]);
        $this->start();

        return true;
    }

    public function cancel(): bool
    {
        if (! $this->canCancel()) {
            return false;
        }

        $this->update(['status' => self::STATUS_CANCELLED, 'completed_at' => now(), 'run_token' => null]);
        $this->recipients()->where('status', CampaignRecipient::STATUS_PENDING)
            ->update(['status' => CampaignRecipient::STATUS_SKIPPED, 'reason' => 'The campaign was cancelled before this call.']);

        return true;
    }

    /* ------------------------------------------------------------------
     | Progress
     | ------------------------------------------------------------------ */

    /**
     * Where the campaign stands, and how the finished calls went.
     *
     * @return array{total: int, pending: int, called: int, skipped: int, done: int, percent: int, outcomes: array<string, int>}
     */
    public function progress(): array
    {
        $byStatus = $this->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $byStatus->sum();
        $pending = (int) ($byStatus[CampaignRecipient::STATUS_PENDING] ?? 0);

        $outcomes = ['booked' => 0, 'answered' => 0, 'opted_out' => 0, 'failed' => 0, 'in_progress' => 0];
        foreach ($this->calls()->with('appointment')->get() as $call) {
            $outcomes[$call->outcome()]++;
        }

        // Before it starts, people skipped up front don't make it "33% done".
        $done = $this->status === self::STATUS_SCHEDULED ? 0 : $total - $pending;

        return [
            'total' => $total,
            'pending' => $pending,
            'called' => (int) ($byStatus[CampaignRecipient::STATUS_CALLED] ?? 0),
            'skipped' => (int) ($byStatus[CampaignRecipient::STATUS_SKIPPED] ?? 0),
            'done' => $done,
            'percent' => $total > 0 ? (int) floor(100 * $done / $total) : 100,
            'outcomes' => $outcomes,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_RUNNING, self::STATUS_PAUSED], true);
    }

    /**
     * True while any call this campaign placed is still ringing or being read.
     */
    public function hasCallsInFlight(): bool
    {
        return $this->calls()->whereIn('status', [Call::STATUS_DIALING, Call::STATUS_RECEIVED, Call::STATUS_PROCESSING])->exists();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_RUNNING => Contact::callingHoursBlocker() ? 'Waiting for calling hours' : 'Calling',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_COMPLETED => 'Finished',
            default => 'Cancelled',
        };
    }

    /**
     * The scheduled time shown in the business timezone.
     */
    public function scheduledLocal(): ?CarbonImmutable
    {
        return $this->scheduled_at?->setTimezone(config('call_assistant.timezone'));
    }
}

<?php

namespace App\Models;

use App\Jobs\DeliverDemoOutcome;
use App\Jobs\ProcessCall;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A phone call handled by an AI voice agent, inbound or outbound.
 *
 * Owns the whole call lifecycle: placing outbound calls through Vapi,
 * receiving the finished call by webhook, analysing the transcript with
 * OpenAI, and asking Appointment to book a slot when one was agreed.
 * Controllers only validate input and call into here.
 */
class Call extends Model
{
    use BelongsToUser;

    public const STATUS_DIALING = 'dialing';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const INTENTS = ['book_appointment', 'reschedule', 'question', 'complaint', 'opt_out', 'other'];

    public const SENTIMENTS = ['positive', 'neutral', 'negative'];

    protected $fillable = [
        'external_id',
        'user_id',
        'contact_id',
        'campaign_id',
        'purpose',
        'caller_name',
        'caller_phone',
        'caller_email',
        'direction',
        'duration_seconds',
        'started_at',
        'transcript',
        'status',
        'summary',
        'intent',
        'sentiment',
        'action_items',
        'booking_requested',
        'requested_start',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'action_items' => 'array',
            'booking_requested' => 'boolean',
            'duration_seconds' => 'integer',
            'started_at' => 'immutable_datetime',
            'requested_start' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function businessName(): string
    {
        return $this->user?->businessName() ?? config('call_assistant.business_name');
    }

    /* ------------------------------------------------------------------
     | Placing outbound calls
     | ------------------------------------------------------------------ */

    public static function usesVapi(): bool
    {
        return filled(config('call_assistant.outbound.vapi_key'))
            && filled(config('call_assistant.outbound.vapi_assistant_id'))
            && filled(config('call_assistant.outbound.vapi_phone_number_id'));
    }

    /**
     * Ring a contact. The call is recorded either way: when it is not allowed
     * or the provider refuses it, it is stored as failed with the reason, so
     * nothing disappears silently. The finished call comes back through the
     * normal webhook with the provider's call ID.
     */
    public static function dial(Contact $contact, string $purpose, ?Campaign $campaign = null): self
    {
        $blocker = $contact->callBlocker();

        $call = static::create([
            'external_id' => 'out_'.Str::lower(Str::random(16)),
            'user_id' => $contact->user_id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign?->id,
            'purpose' => Str::limit(trim($purpose), 180),
            'caller_name' => $contact->name,
            'caller_phone' => $contact->phone,
            'caller_email' => $contact->email,
            'direction' => 'outbound',
            'started_at' => now(),
            'status' => $blocker ? self::STATUS_FAILED : self::STATUS_DIALING,
            'error' => $blocker,
        ]);

        if ($blocker) {
            return $call;
        }

        try {
            if (static::usesVapi()) {
                $call->update(['external_id' => $call->placeVapiCall($contact)]);
            } else {
                DeliverDemoOutcome::dispatch($call)->delay(now()->addSeconds(6));
            }

            $contact->update(['last_called_at' => now()]);
        } catch (Throwable $e) {
            report($e);

            $call->update([
                'status' => self::STATUS_FAILED,
                'error' => 'The call could not be placed: '.Str::limit($e->getMessage(), 400),
            ]);
        }

        return $call;
    }

    /**
     * Ask Vapi to place the call and return Vapi's call ID.
     */
    protected function placeVapiCall(Contact $contact): string
    {
        $response = Http::withToken(config('call_assistant.outbound.vapi_key'))
            ->acceptJson()
            ->timeout(20)
            ->post(config('call_assistant.outbound.vapi_base_url').'/call', [
                'assistantId' => config('call_assistant.outbound.vapi_assistant_id'),
                'phoneNumberId' => config('call_assistant.outbound.vapi_phone_number_id'),
                'customer' => array_filter([
                    'number' => $contact->phone,
                    'name' => $contact->name,
                ]),
                'assistantOverrides' => [
                    'variableValues' => [
                        'customer_name' => $contact->name ?: 'there',
                        'purpose' => $this->purpose,
                        'business_name' => $this->businessName(),
                    ],
                ],
            ])
            ->throw();

        $providerId = $response->json('id');

        if (blank($providerId)) {
            throw new RuntimeException('Vapi did not return a call ID.');
        }

        return (string) $providerId;
    }

    /**
     * Demo mode: the "callee" answers with a sample conversation that fits
     * the reason for the call, then the call is analysed like any other.
     */
    public function completeDemoOutboundCall(): void
    {
        if ($this->status !== self::STATUS_DIALING) {
            return;
        }

        $transcript = $this->demoOutboundTranscript();

        $this->update([
            'transcript' => $transcript,
            'duration_seconds' => 30 + 9 * strlen(implode(' ', array_column($transcript, 'text'))) % 150,
            'status' => self::STATUS_RECEIVED,
        ]);

        $this->process();
    }

    /**
     * Demo mode: each person picks their own time, so demo bookings spread
     * over the day instead of all landing on the same slot.
     */
    protected function demoSlotHour(): int
    {
        return [9, 10, 11, 14, 15, 16][(int) substr(preg_replace('/\D/', '', (string) $this->caller_phone), -3) % 6];
    }

    /**
     * The first free slot from this person's preferred hour, the next working day.
     */
    protected function demoFreeSlot(?int $fromHour = null): CarbonImmutable
    {
        $start = CarbonImmutable::now(config('call_assistant.timezone'))->addWeekdays(1)->setTime($fromHour ?? $this->demoSlotHour(), 0);
        $length = (int) config('call_assistant.default_duration_minutes');

        for ($try = 0; $try < 16 && Appointment::bookedOverlapping($this->user_id, $start, $start->addMinutes($length)); $try++) {
            $start = $start->addHour()->hour >= 17 ? $start->addWeekday()->setTime(9, 0) : $start->addHour();
        }

        return $start;
    }

    protected function demoSlotLabel(): string
    {
        return $this->demoFreeSlot()->format('g A');
    }

    protected function demoOutboundTranscript(): array
    {
        $first = Str::before((string) ($this->caller_name ?: 'there'), ' ');
        $business = $this->businessName();
        $opening = ['role' => 'agent', 'text' => "Hi {$first}, this is Ava calling from {$business}. I'm calling about: ".Str::lower($this->purpose).'.'];

        // The number's last digit picks how the person answers, about like real
        // campaigns go: ending in 9 opts out, 7 or 8 asks for a callback, the rest book.
        $lastDigit = (int) substr((string) $this->caller_phone, -1);

        return match (true) {
            $lastDigit < 7 => [
                $opening,
                ['role' => 'caller', 'text' => 'Oh good, thanks for calling. Can you book me in on the next working day?'],
                ['role' => 'agent', 'text' => "Of course. I have {$this->demoSlotLabel()} free {$this->demoFreeSlot()->format('l')}. Shall I book it?"],
                ['role' => 'caller', 'text' => "Yes, {$this->demoFreeSlot()->format('l')} at {$this->demoSlotLabel()} is perfect, thank you!"],
                ['role' => 'agent', 'text' => 'Done. You will get a text to confirm.'],
            ],
            $lastDigit < 9 => [
                $opening,
                ['role' => 'caller', 'text' => 'I am driving right now. Could someone call me back later this week?'],
                ['role' => 'agent', 'text' => 'Of course. I will ask the team to call you back later this week.'],
                ['role' => 'caller', 'text' => 'Okay, thanks.'],
            ],
            default => [
                $opening,
                ['role' => 'caller', 'text' => 'I am not interested. Please stop calling this number.'],
                ['role' => 'agent', 'text' => 'Understood. I have removed your number, and we will not call again. Have a good day.'],
            ],
        };
    }

    /* ------------------------------------------------------------------
     | Receiving calls
     | ------------------------------------------------------------------ */

    /**
     * Check the X-Signature header sent with a webhook ("sha256=<hex hmac>").
     */
    public static function hasValidSignature(string $payload, ?string $signatureHeader): bool
    {
        $secret = (string) config('call_assistant.webhook_secret');

        if ($secret === '' || blank($signatureHeader)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, Str::after($signatureHeader, 'sha256='));
    }

    /**
     * Store a finished call from a validated webhook payload and queue it for
     * analysis. Providers retry webhooks, so a repeated call_id is ignored,
     * except for an outbound call we placed, which is completed by it.
     *
     * A new (inbound) call needs the account it was made to; an outbound call
     * we placed already knows its account.
     */
    public static function receiveFromWebhook(array $data, ?User $user = null, bool $processNow = false): self
    {
        $call = static::firstOrNew(['external_id' => $data['call_id']]);

        if ($call->exists && $user && $call->user_id !== $user->id) {
            throw new InvalidArgumentException('This call belongs to another account.');
        }

        if (! $call->exists && ! $user) {
            throw new InvalidArgumentException("Unknown call. Send new calls to your account's own webhook URL, shown on the Settings page.");
        }

        if ($call->exists && $call->status !== self::STATUS_DIALING) {
            return $call;
        }

        $call->user_id ??= $user?->id;

        $call->fill([
            'caller_name' => $call->caller_name ?: Arr::get($data, 'caller.name'),
            'caller_phone' => $call->caller_phone ?: Arr::get($data, 'caller.phone'),
            'caller_email' => $call->caller_email ?: Arr::get($data, 'caller.email'),
            'direction' => $call->exists ? $call->direction : ($data['direction'] ?? 'inbound'),
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'started_at' => isset($data['started_at'])
                ? CarbonImmutable::parse($data['started_at'])->setTimezone(config('app.timezone'))
                : ($call->started_at ?? now()),
            'transcript' => static::normalizeTranscript($data['transcript']),
            'status' => self::STATUS_RECEIVED,
        ]);
        $call->save();

        $processNow ? $call->process() : ProcessCall::dispatch($call);

        return $call;
    }

    /**
     * Create a call from one of the sample transcripts in the config, exactly
     * as if a voice provider had sent it to the webhook.
     */
    public static function simulate(User $user, bool $processNow = false): self
    {
        $sample = Arr::random(config('call_assistant.samples'));

        return static::receiveFromWebhook([
            'call_id' => 'sim_'.Str::lower(Str::random(12)),
            'direction' => 'inbound',
            'started_at' => now()->subSeconds($sample['duration_seconds'])->toIso8601String(),
        ] + $sample, $user, $processNow);
    }

    /**
     * Put a failed call back on the queue. Returns false when the call has
     * not failed, so there is nothing to retry.
     */
    public function retry(): bool
    {
        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }

        $this->update(['status' => self::STATUS_RECEIVED, 'error' => null]);
        ProcessCall::dispatch($this);

        return true;
    }

    /* ------------------------------------------------------------------
     | Processing
     | ------------------------------------------------------------------ */

    /**
     * Analyse the transcript and book an appointment if one was requested.
     * Any failure is stored on the call so it shows on the dashboard.
     */
    public function process(): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return;
        }

        $this->update(['status' => self::STATUS_PROCESSING, 'error' => null]);

        try {
            $analysis = $this->analyze();

            $this->fill([
                'summary' => $analysis['summary'],
                'intent' => $analysis['intent'],
                'sentiment' => $analysis['sentiment'],
                'action_items' => $analysis['action_items'],
                'booking_requested' => $analysis['booking']['requested'],
                'requested_start' => $analysis['booking']['start']?->setTimezone(config('app.timezone')),
            ])->save();

            if ($analysis['intent'] === 'opt_out') {
                $this->contact?->optOut();
                $this->user?->contacts()->where('phone', $this->caller_phone)->first()?->optOut();
            } elseif ($analysis['booking']['requested']) {
                Appointment::bookFromCall($this, $analysis['booking']);
            }

            $this->update(['status' => self::STATUS_COMPLETED, 'processed_at' => now()]);
        } catch (Throwable $e) {
            report($e);

            $this->update([
                'status' => self::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 500),
            ]);
        }
    }

    /**
     * Summary, intent, sentiment, action items and booking details, cleaned
     * up so nothing the model returns can break the rest of the flow.
     *
     * @return array{summary: string, intent: string, sentiment: string, action_items: array<int, string>, booking: array{requested: bool, start: ?CarbonImmutable, duration_minutes: int, title: ?string}}
     */
    public function analyze(): array
    {
        $raw = static::usesDemoMode() ? $this->demoAnalysis() : $this->analyzeWithOpenAi();

        return $this->normalizeAnalysis($raw);
    }

    public static function usesDemoMode(): bool
    {
        return blank(config('call_assistant.openai.key'));
    }

    protected function analyzeWithOpenAi(): array
    {
        $response = Http::withToken(config('call_assistant.openai.key'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500)
            ->post(config('call_assistant.openai.base_url').'/chat/completions', [
                'model' => config('call_assistant.openai.model'),
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->analysisPrompt()],
                    ['role' => 'user', 'content' => ($this->direction === 'outbound'
                        ? "This was an outbound call the receptionist made. Reason for calling: {$this->purpose}.\n\n"
                        : '').$this->transcriptAsText()],
                ],
            ]);

        $decoded = json_decode((string) $response->json('choices.0.message.content'), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI did not return valid JSON for this call.');
        }

        return $decoded;
    }

    protected function analysisPrompt(): string
    {
        $timezone = config('call_assistant.timezone');
        $now = CarbonImmutable::now($timezone);
        $business = $this->businessName();
        $intents = implode(', ', self::INTENTS);
        $sentiments = implode(', ', self::SENTIMENTS);
        $duration = config('call_assistant.default_duration_minutes');

        return <<<PROMPT
        You analyse phone calls between an AI receptionist for "{$business}" and a caller.
        The current date and time is {$now->toDayDateTimeString()} ({$timezone}).

        Reply with a JSON object with exactly these keys:
        - "summary": 1 to 3 sentences on what the caller wanted and what was agreed.
        - "intent": one of {$intents}.
        - "sentiment": the caller's overall sentiment, one of {$sentiments}.
        - "action_items": an array of short follow-up tasks for the staff (can be empty).
        - "booking": an object with
            - "requested": true only if an appointment time was agreed on the call,
            - "start": the agreed start as ISO 8601 with offset in {$timezone}, or null,
            - "duration_minutes": the length if mentioned, otherwise {$duration},
            - "title": a short calendar event title, or null.

        Use "opt_out" whenever the caller asks not to be called again, whatever else was said.
        Resolve relative dates such as "tomorrow" or "next Monday" from the current date.
        Never invent a time that was not agreed on the call.
        PROMPT;
    }

    /**
     * Rule-based stand-in for the AI, used when no OpenAI key is configured.
     */
    protected function demoAnalysis(): array
    {
        $callerText = collect($this->transcript)->where('role', 'caller')->pluck('text')->implode(' ');
        $who = Str::before(trim((string) $this->caller_name), ' ') ?: 'The caller';

        if (preg_match('/\b(stop calling|do not call|don\'t call|remove (me|my number))\b/i', $callerText)) {
            return [
                'summary' => "{$who} asked not to be called again, so the number is now blocked from all future calls.",
                'intent' => 'opt_out',
                'sentiment' => 'negative',
                'action_items' => [],
                'booking' => ['requested' => false],
            ];
        }

        $wantsBooking = (bool) preg_match('/\b(book|appointment|schedule|reserve)\b/i', $callerText);
        $wantsCallback = (bool) preg_match('/\b(call me back|call back|later this week|driving|busy right now)\b/i', $callerText);
        $sentiment = match (true) {
            (bool) preg_match('/\b(frustrated|angry|terrible|disappointed|charged twice|wrong)\b/i', $callerText) => 'negative',
            (bool) preg_match('/\b(thanks|thank you|great|perfect)\b/i', $callerText) => 'positive',
            default => 'neutral',
        };
        $intent = match (true) {
            $wantsBooking => 'book_appointment',
            $sentiment === 'negative' => 'complaint',
            $wantsCallback => 'other',
            default => 'question',
        };
        // The time the caller said ("10 AM"); if they named none, the first free
        // slot from a time of their own, the way a receptionist would offer one.
        if (preg_match('/\b(1[0-2]|[1-9])\s?(AM|PM)\b/i', $callerText, $said)) {
            $now = CarbonImmutable::now(config('call_assistant.timezone'));
            $day = preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $callerText, $weekday)
                ? $now->next(ucfirst(strtolower($weekday[1])))
                : $now->addWeekdays(1);
            $start = $day->setTime(((int) $said[1] % 12) + (strtoupper($said[2]) === 'PM' ? 12 : 0), 0);
        } else {
            $start = $this->demoFreeSlot();
        }

        // Written the way a receptionist would note the call.
        $visit = match (true) {
            (bool) preg_match('/\bcleaning\b/i', $callerText) => 'a cleaning',
            (bool) preg_match('/\bcheck-?up\b/i', $callerText.' '.$this->purpose) => 'a check-up',
            default => 'an appointment',
        };
        $topics = collect([
            'insurance' => '/\binsurance|plan\b/i',
            'opening hours' => '/\bopen|hours|saturday|sunday\b/i',
            'prices' => '/\bprice|cost|how much\b/i',
        ])->filter(fn ($pattern) => preg_match($pattern, $callerText))->keys();

        $summary = match ($intent) {
            'book_appointment' => "{$who} asked for {$visit} on {$start->format('l')} at {$start->format('g A')}, and the AI booked it.",
            'complaint' => $who.' is upset'.(preg_match('/charged twice/i', $callerText) ? ' about being charged twice for a recent visit' : ' about a recent experience').' and wants someone to call back.',
            'other' => "{$who} couldn't talk right now and asked to be called back later this week.",
            default => $topics->isNotEmpty()
                ? "{$who} asked about ".$topics->join(', ', ' and ').' and got an answer on the call.'
                : "{$who} had a quick question and got an answer on the call.",
        };

        return [
            'summary' => $summary,
            'intent' => $intent,
            'sentiment' => $sentiment,
            'action_items' => match ($intent) {
                'book_appointment' => ['Send '.($who === 'The caller' ? 'the caller' : $who).' a booking confirmation'],
                'complaint' => ['Call '.($who === 'The caller' ? 'the caller' : $who).' back about the issue today'],
                'other' => ['Call back later this week'],
                default => ['Follow up if anything else is needed'],
            },
            'booking' => [
                'requested' => $wantsBooking,
                'start' => $wantsBooking ? $start->toIso8601String() : null,
                'duration_minutes' => config('call_assistant.default_duration_minutes'),
                'title' => null,
            ],
        ];
    }

    protected function normalizeAnalysis(array $raw): array
    {
        $booking = is_array($raw['booking'] ?? null) ? $raw['booking'] : [];
        $start = null;

        if (! empty($booking['requested']) && ! empty($booking['start'])) {
            try {
                $start = CarbonImmutable::parse($booking['start'], config('call_assistant.timezone'));
            } catch (Throwable) {
                $start = null;
            }

            if ($start?->isPast()) {
                $start = null;
            }
        }

        $actionItems = collect(is_array($raw['action_items'] ?? null) ? $raw['action_items'] : [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => Str::limit(trim($item), 200))
            ->take(10)
            ->values()
            ->all();

        $title = is_string($booking['title'] ?? null) ? trim($booking['title']) : '';

        return [
            'summary' => Str::limit(trim((string) ($raw['summary'] ?? '')), 1000),
            'intent' => in_array($raw['intent'] ?? null, self::INTENTS, true) ? $raw['intent'] : 'other',
            'sentiment' => in_array($raw['sentiment'] ?? null, self::SENTIMENTS, true) ? $raw['sentiment'] : 'neutral',
            'action_items' => $actionItems,
            'booking' => [
                'requested' => $start !== null,
                'start' => $start,
                'duration_minutes' => max(15, min(240, (int) ($booking['duration_minutes'] ?? config('call_assistant.default_duration_minutes')))),
                'title' => $title !== '' ? Str::limit($title, 120) : null,
            ],
        ];
    }

    /* ------------------------------------------------------------------
     | Presentation
     | ------------------------------------------------------------------ */

    public function callerLabel(): string
    {
        return $this->caller_name ?: ($this->caller_phone ?: 'Unknown caller');
    }

    public function transcriptAsText(): string
    {
        return collect($this->transcript ?? [])
            ->map(fn (array $turn) => ($turn['role'] === 'agent' ? 'Agent' : 'Caller').': '.$turn['text'])
            ->implode("\n");
    }

    /**
     * Everything the dashboard shows: summary stats plus the latest calls.
     */
    public static function forDashboard(User $user, int $limit = 50): array
    {
        $calls = $user->calls()->with(['appointment', 'campaign'])->latest('id')->limit($limit)->get();

        return [
            'stats' => static::statsFor($user),
            'calls' => $calls->map->toDashboardArray()->all(),
        ];
    }

    /**
     * @return array{total: int, today: int, booked: int, in_progress: int, needs_attention: int}
     */
    public static function statsFor(User $user): array
    {
        $appointments = Appointment::whereHas('call', fn ($query) => $query->ownedBy($user));

        return [
            'total' => $user->calls()->count(),
            'today' => $user->calls()->where('created_at', '>=', now()->startOfDay())->count(),
            'booked' => (clone $appointments)->where('status', Appointment::STATUS_BOOKED)->count(),
            'in_progress' => $user->calls()->whereIn('status', [self::STATUS_DIALING, self::STATUS_RECEIVED, self::STATUS_PROCESSING])->count(),
            'needs_attention' => $user->calls()->where('status', self::STATUS_FAILED)->count()
                + (clone $appointments)->whereIn('status', [Appointment::STATUS_CONFLICT, Appointment::STATUS_FAILED])->count(),
        ];
    }

    /**
     * One word for how the call ended, for lists and campaign results.
     */
    public function outcome(): string
    {
        return match (true) {
            $this->status === self::STATUS_FAILED => 'failed',
            $this->status !== self::STATUS_COMPLETED => 'in_progress',
            $this->appointment?->status === Appointment::STATUS_BOOKED => 'booked',
            $this->intent === 'opt_out' => 'opted_out',
            default => 'answered',
        };
    }

    public function toDashboardArray(): array
    {
        $timezone = config('call_assistant.timezone');

        return [
            'id' => $this->id,
            'caller' => $this->callerLabel(),
            'caller_name' => $this->caller_name,
            'caller_phone' => $this->caller_phone,
            'caller_phone_display' => $this->caller_phone ? Contact::formatPhone($this->caller_phone) : null,
            'caller_email' => $this->caller_email,
            'direction' => $this->direction,
            'purpose' => $this->purpose,
            'contact_id' => $this->contact_id,
            'campaign' => $this->campaign ? ['id' => $this->campaign->id, 'name' => $this->campaign->name] : null,
            'duration_seconds' => $this->duration_seconds,
            'started_at' => $this->started_at?->setTimezone($timezone)->toIso8601String(),
            'status' => $this->status,
            'is_finished' => in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true),
            'summary' => $this->summary,
            'intent' => $this->intent,
            'sentiment' => $this->sentiment,
            'action_items' => $this->action_items ?? [],
            'transcript' => $this->transcript ?? [],
            'error' => $this->error,
            'appointment' => $this->appointment?->toDashboardArray(),
        ];
    }

    /**
     * @return array<int, array{role: string, text: string}>
     */
    protected static function normalizeTranscript(array $turns): array
    {
        return collect($turns)
            ->map(fn (array $turn) => [
                'role' => $turn['role'] === 'agent' ? 'agent' : 'caller',
                'text' => trim((string) $turn['text']),
            ])
            ->filter(fn (array $turn) => $turn['text'] !== '')
            ->values()
            ->all();
    }
}

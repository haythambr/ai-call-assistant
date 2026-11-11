<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An appointment agreed on a call, booked into Google Calendar.
 *
 * Holds the Google Calendar integration: service-account sign-in, the
 * free/busy check and event creation. Without Google credentials the
 * booking is recorded in demo mode instead.
 */
class Appointment extends Model
{
    public const STATUS_BOOKED = 'booked';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_FAILED = 'failed';

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_DEMO = 'demo';

    protected const GOOGLE_API = 'https://www.googleapis.com/calendar/v3';

    protected $fillable = [
        'call_id',
        'title',
        'starts_at',
        'ends_at',
        'timezone',
        'provider',
        'status',
        'external_event_id',
        'external_event_link',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /* ------------------------------------------------------------------
     | Booking
     | ------------------------------------------------------------------ */

    /**
     * Book the slot the caller agreed to. Calling this again for the same call
     * reuses the existing record and never creates a second calendar event.
     *
     * @param  array{start: CarbonImmutable, duration_minutes: int, title: ?string}  $booking
     */
    public static function bookFromCall(Call $call, array $booking): self
    {
        $appointment = $call->appointment()->firstOrNew();

        if ($appointment->status === self::STATUS_BOOKED) {
            return $appointment;
        }

        $start = $booking['start']->setTimezone(config('app.timezone'));

        $appointment->fill([
            'title' => $booking['title'] ?: 'Appointment: '.$call->callerLabel(),
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($booking['duration_minutes']),
            'timezone' => config('call_assistant.timezone'),
            'provider' => static::usesGoogleCalendar() ? self::PROVIDER_GOOGLE : self::PROVIDER_DEMO,
            'status' => self::STATUS_FAILED,
            'error' => null,
        ])->save();

        $appointment->setRelation('call', $call);
        $appointment->schedule();

        return $appointment;
    }

    /**
     * Put the appointment in the calendar if the slot is still free. Calendar
     * errors are stored on the appointment rather than failing the call.
     */
    public function schedule(): void
    {
        if ($this->provider === self::PROVIDER_DEMO) {
            // The demo calendar is this account's own bookings, checked like Google's free/busy.
            if ($this->overlapsAnotherBooking()) {
                $this->update([
                    'status' => self::STATUS_CONFLICT,
                    'error' => 'That time is already taken in the calendar, so nothing was booked.',
                ]);

                return;
            }

            $this->update([
                'status' => self::STATUS_BOOKED,
                'external_event_id' => 'demo_'.Str::lower(Str::random(12)),
                'external_event_link' => null,
            ]);

            return;
        }

        try {
            if (! $this->slotIsFree()) {
                $this->update([
                    'status' => self::STATUS_CONFLICT,
                    'error' => 'That time is already taken in the calendar, so nothing was booked.',
                ]);

                return;
            }

            $event = $this->createGoogleEvent();

            $this->update([
                'status' => self::STATUS_BOOKED,
                'external_event_id' => $event['id'] ?? null,
                'external_event_link' => $event['htmlLink'] ?? null,
                'error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            $this->update([
                'status' => self::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 500),
            ]);
        }
    }

    /* ------------------------------------------------------------------
     | Google Calendar
     | ------------------------------------------------------------------ */

    /**
     * Demo mode's free/busy: another booked appointment on the same account
     * that overlaps this one.
     */
    public function overlapsAnotherBooking(): bool
    {
        return static::bookedOverlapping($this->call?->user_id, $this->starts_at, $this->ends_at, $this->id);
    }

    public static function bookedOverlapping(?int $userId, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptId = null): bool
    {
        // Times are stored in the app timezone, and query bindings drop the offset.
        $appTimezone = config('app.timezone');

        return static::where('status', self::STATUS_BOOKED)
            ->whereHas('call', fn ($query) => $query->where('user_id', $userId))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('starts_at', '<', $end->setTimezone($appTimezone))
            ->where('ends_at', '>', $start->setTimezone($appTimezone))
            ->exists();
    }

    public static function usesGoogleCalendar(): bool
    {
        return filled(config('call_assistant.google_calendar.calendar_id'))
            && is_file(static::googleCredentialsPath());
    }

    protected function slotIsFree(): bool
    {
        $calendarId = config('call_assistant.google_calendar.calendar_id');

        $calendars = Http::withToken(static::googleAccessToken())
            ->acceptJson()
            ->timeout(20)
            ->post(self::GOOGLE_API.'/freeBusy', [
                'timeMin' => $this->starts_at->toRfc3339String(),
                'timeMax' => $this->ends_at->toRfc3339String(),
                'items' => [['id' => $calendarId]],
            ])
            ->throw()
            ->json('calendars', []);

        // Read by key: calendar IDs are emails, and dots break dot-notation lookups.
        return empty($calendars[$calendarId]['busy'] ?? []);
    }

    protected function createGoogleEvent(): array
    {
        $calendarId = config('call_assistant.google_calendar.calendar_id');

        return Http::withToken(static::googleAccessToken())
            ->acceptJson()
            ->timeout(20)
            ->post(self::GOOGLE_API.'/calendars/'.rawurlencode($calendarId).'/events', [
                'summary' => $this->title,
                'description' => $this->eventDescription(),
                'start' => [
                    'dateTime' => $this->starts_at->setTimezone($this->timezone)->toRfc3339String(),
                    'timeZone' => $this->timezone,
                ],
                'end' => [
                    'dateTime' => $this->ends_at->setTimezone($this->timezone)->toRfc3339String(),
                    'timeZone' => $this->timezone,
                ],
            ])
            ->throw()
            ->json();
    }

    protected function eventDescription(): string
    {
        $call = $this->call;

        return collect([
            'Caller: '.$call->callerLabel(),
            $call->caller_phone ? 'Phone: '.$call->caller_phone : null,
            $call->caller_email ? 'Email: '.$call->caller_email : null,
            $call->summary ? "\nCall summary:\n".$call->summary : null,
            "\nBooked automatically by AI Call Assistant (call #{$call->id}).",
        ])->filter()->implode("\n");
    }

    /**
     * OAuth access token for the service account, cached until shortly
     * before it expires.
     */
    protected static function googleAccessToken(): string
    {
        return Cache::remember('google_calendar.access_token', now()->addMinutes(50), function (): string {
            $credentials = static::googleCredentials();
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $issuedAt = time();

            $unsigned = static::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
                .'.'.static::base64Url(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/calendar',
                    'aud' => $tokenUri,
                    'iat' => $issuedAt,
                    'exp' => $issuedAt + 3600,
                ]));

            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the Google service account token. Check the private key.');
            }

            $token = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $unsigned.'.'.static::base64Url($signature),
                ])
                ->throw()
                ->json('access_token');

            if (blank($token)) {
                throw new RuntimeException('Google did not return an access token.');
            }

            return $token;
        });
    }

    protected static function googleCredentials(): array
    {
        $credentials = json_decode((string) file_get_contents(static::googleCredentialsPath()), true);

        if (! is_array($credentials) || blank($credentials['client_email'] ?? null) || blank($credentials['private_key'] ?? null)) {
            throw new RuntimeException('The Google service account file is missing client_email or private_key.');
        }

        return $credentials;
    }

    protected static function googleCredentialsPath(): string
    {
        $path = (string) config('call_assistant.google_calendar.credentials');

        // Absolute on Linux/macOS ("/...") or Windows ("C:\..." or "\\server\..."); otherwise relative to the project.
        $isAbsolute = Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    protected static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /* ------------------------------------------------------------------
     | Presentation
     | ------------------------------------------------------------------ */

    public function toDashboardArray(): array
    {
        return [
            'title' => $this->title,
            'starts_at' => $this->starts_at->setTimezone($this->timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->setTimezone($this->timezone)->toIso8601String(),
            'timezone' => $this->timezone,
            'provider' => $this->provider,
            'status' => $this->status,
            'link' => $this->external_event_link,
            'error' => $this->error,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Call;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CallWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    private const CALENDAR_ID = 'front.desk@example.com';

    private string $credentialsPath;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->actingAs($this->user); // for the retry and simulate buttons
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T09:00:00-05:00'));

        config([
            'call_assistant.webhook_secret' => self::SECRET,
            'call_assistant.timezone' => 'America/New_York',
            'call_assistant.openai.key' => 'sk-test',
            'call_assistant.google_calendar.calendar_id' => self::CALENDAR_ID,
            'call_assistant.google_calendar.credentials' => $this->credentialsPath = $this->makeServiceAccountFile(),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    public function test_it_rejects_a_webhook_with_a_bad_signature(): void
    {
        $this->call('POST', '/api/webhooks/calls/'.$this->user->webhook_token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => 'sha256=not-the-right-signature',
        ], json_encode($this->payload()))->assertUnauthorized();

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_it_validates_the_payload(): void
    {
        $this->postSigned(['call_id' => 'abc', 'transcript' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transcript');
    }

    public function test_it_analyses_the_call_and_books_a_google_calendar_event(): void
    {
        $this->fakeOpenAi($this->bookingAnalysis());
        $this->fakeGoogle(busy: false);

        $this->postSigned($this->payload())->assertStatus(202);

        $call = Call::with('appointment')->sole();

        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertSame('book_appointment', $call->intent);
        $this->assertSame('positive', $call->sentiment);
        $this->assertSame(['Send a confirmation text'], $call->action_items);

        $appointment = $call->appointment;
        $this->assertSame(Appointment::STATUS_BOOKED, $appointment->status);
        $this->assertSame(Appointment::PROVIDER_GOOGLE, $appointment->provider);
        $this->assertSame('evt_123', $appointment->external_event_id);
        $this->assertSame('2026-11-11T10:00:00-05:00', $appointment->starts_at->setTimezone('America/New_York')->toIso8601String());
        $this->assertSame('2026-11-11T10:30:00-05:00', $appointment->ends_at->setTimezone('America/New_York')->toIso8601String());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/calendars/front.desk%40example.com/events')
            && $request['summary'] === 'Teeth cleaning: Sarah Mitchell'
            && $request['start']['timeZone'] === 'America/New_York'
            && str_contains($request['description'], '+1 415 555 0142'));
    }

    public function test_it_does_not_double_book_a_busy_slot(): void
    {
        $this->fakeOpenAi($this->bookingAnalysis());
        $this->fakeGoogle(busy: true);

        $this->postSigned($this->payload())->assertStatus(202);

        $call = Call::with('appointment')->sole();
        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertSame(Appointment::STATUS_CONFLICT, $call->appointment->status);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/events'));
    }

    public function test_repeated_webhooks_for_the_same_call_are_ignored(): void
    {
        $this->fakeOpenAi($this->bookingAnalysis());
        $this->fakeGoogle(busy: false);

        $this->postSigned($this->payload())->assertStatus(202);
        $this->postSigned($this->payload())->assertStatus(202);

        $this->assertDatabaseCount('calls', 1);
        $this->assertDatabaseCount('appointments', 1);
        Http::assertSentCount(4); // OpenAI, token, free/busy, event: once each
    }

    public function test_a_time_in_the_past_is_never_booked(): void
    {
        $this->fakeOpenAi(['booking' => ['requested' => true, 'start' => '2026-11-01T10:00:00-05:00']] + $this->bookingAnalysis());

        $this->postSigned($this->payload())->assertStatus(202);

        $call = Call::sole();
        $this->assertFalse($call->booking_requested);
        $this->assertNull($call->appointment);
    }

    public function test_an_openai_failure_marks_the_call_failed_and_it_can_be_retried(): void
    {
        $rateLimited = Http::response(['error' => ['message' => 'Rate limit reached']], 429);
        $analysis = ['booking' => ['requested' => false]] + $this->bookingAnalysis();

        // Two attempts fail (the request is tried twice), then the manual retry succeeds.
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->pushResponse($rateLimited)
                ->pushResponse($rateLimited)
                ->push(['choices' => [['message' => ['content' => json_encode($analysis)]]]]),
        ]);

        $this->postSigned($this->payload())->assertStatus(202);

        $call = Call::sole();
        $this->assertSame(Call::STATUS_FAILED, $call->status);
        $this->assertNotEmpty($call->error);

        $this->postJson("/calls/{$call->id}/retry")->assertOk();

        $this->assertSame(Call::STATUS_COMPLETED, $call->fresh()->status);
    }

    public function test_only_failed_calls_can_be_retried(): void
    {
        $this->fakeOpenAi(['booking' => ['requested' => false]] + $this->bookingAnalysis());
        $this->postSigned($this->payload());

        $this->postJson('/calls/'.Call::sole()->id.'/retry')->assertStatus(422);
    }

    public function test_demo_mode_works_without_any_api_keys(): void
    {
        config([
            'call_assistant.openai.key' => null,
            'call_assistant.google_calendar.calendar_id' => null,
        ]);

        $this->postSigned($this->payload())->assertStatus(202);

        $call = Call::with('appointment')->sole();
        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertSame('book_appointment', $call->intent);
        $this->assertSame(Appointment::PROVIDER_DEMO, $call->appointment->provider);
        $this->assertSame(Appointment::STATUS_BOOKED, $call->appointment->status);
        Http::assertNothingSent();
    }

    public function test_the_demo_calendar_never_double_books_either(): void
    {
        config(['call_assistant.openai.key' => null, 'call_assistant.google_calendar.calendar_id' => null]);

        // Both callers ask for 10 AM tomorrow.
        $this->postSigned($this->payload())->assertStatus(202);
        $this->postSigned(['call_id' => 'call_second'] + $this->payload())->assertStatus(202);

        [$first, $second] = Call::with('appointment')->orderBy('id')->get();
        $this->assertSame(Appointment::STATUS_BOOKED, $first->appointment->status);
        $this->assertSame(Appointment::STATUS_CONFLICT, $second->appointment->status);

        // Another account's calendar is separate.
        $other = User::factory()->create();
        $this->postSigned(['call_id' => 'call_other'] + $this->payload(), '/api/webhooks/calls/'.$other->webhook_token);
        $this->assertSame(Appointment::STATUS_BOOKED, Call::where('external_id', 'call_other')->sole()->appointment->status);
    }

    public function test_when_no_time_was_named_the_demo_offers_the_next_free_slot(): void
    {
        config(['call_assistant.openai.key' => null, 'call_assistant.google_calendar.calendar_id' => null]);
        $vague = fn (string $id) => ['call_id' => $id, 'transcript' => [
            ['role' => 'agent', 'text' => 'Bright Smile Dental, how can I help?'],
            ['role' => 'caller', 'text' => 'I would like to book an appointment please.'],
        ]] + $this->payload();

        $this->postSigned($vague('call_a'))->assertStatus(202);
        $this->postSigned($vague('call_b'))->assertStatus(202);

        [$a, $b] = Call::with('appointment')->orderBy('id')->get();
        $this->assertSame(Appointment::STATUS_BOOKED, $a->appointment->status);
        $this->assertSame(Appointment::STATUS_BOOKED, $b->appointment->status);
        $this->assertTrue($b->appointment->starts_at->gte($a->appointment->ends_at));
    }

    public function test_a_new_call_lands_in_the_account_its_url_belongs_to(): void
    {
        $this->fakeOpenAi(['booking' => ['requested' => false]] + $this->bookingAnalysis());

        $this->postSigned($this->payload())->assertStatus(202);

        $this->assertSame($this->user->id, Call::sole()->user_id);
    }

    public function test_a_new_call_without_an_account_url_is_refused(): void
    {
        $this->postSigned($this->payload(), '/api/webhooks/calls')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Settings page'));

        $this->postSigned($this->payload(), '/api/webhooks/calls/'.str_repeat('a', 40))->assertNotFound();

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_one_account_cannot_complete_another_accounts_call(): void
    {
        $this->fakeOpenAi(['booking' => ['requested' => false]] + $this->bookingAnalysis());
        $this->postSigned($this->payload())->assertStatus(202);

        $other = User::factory()->create();
        $this->postSigned($this->payload(), '/api/webhooks/calls/'.$other->webhook_token)->assertUnprocessable();

        $this->assertSame($this->user->id, Call::sole()->user_id);
    }

    public function test_the_dashboard_lists_calls(): void
    {
        config(['call_assistant.openai.key' => null, 'call_assistant.google_calendar.calendar_id' => null]);

        $this->get('/calls')->assertOk()->assertSee('Call desk');

        $this->postJson('/calls/simulate')->assertCreated();

        $this->getJson('/calls/feed')
            ->assertOk()
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('calls.0.status', Call::STATUS_COMPLETED);
    }

    /* Helpers -------------------------------------------------------------- */

    private function payload(): array
    {
        return [
            'call_id' => 'call_8f2k1',
            'direction' => 'inbound',
            'duration_seconds' => 146,
            'started_at' => '2026-11-10T08:55:00-05:00',
            'caller' => ['name' => 'Sarah Mitchell', 'phone' => '+1 415 555 0142', 'email' => 'sarah@example.com'],
            'transcript' => [
                ['role' => 'agent', 'text' => 'Bright Smile Dental, how can I help?'],
                ['role' => 'caller', 'text' => 'I would like to book a cleaning tomorrow at 10 AM.'],
                ['role' => 'agent', 'text' => 'You are booked for 10 AM tomorrow.'],
                ['role' => 'caller', 'text' => 'Perfect, thanks!'],
            ],
        ];
    }

    private function bookingAnalysis(): array
    {
        return [
            'summary' => 'Sarah booked a cleaning for tomorrow at 10 AM.',
            'intent' => 'book_appointment',
            'sentiment' => 'positive',
            'action_items' => ['Send a confirmation text', '', 42],
            'booking' => [
                'requested' => true,
                'start' => '2026-11-11T10:00:00-05:00',
                'duration_minutes' => 30,
                'title' => 'Teeth cleaning: Sarah Mitchell',
            ],
        ];
    }

    private function postSigned(array $payload, ?string $url = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', $url ?? '/api/webhooks/calls/'.$this->user->webhook_token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function fakeOpenAi(array $analysis): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($analysis)]]],
            ]),
        ]);
    }

    private function fakeGoogle(bool $busy): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-test-token', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [self::CALENDAR_ID => ['busy' => $busy ? [['start' => '2026-11-11T15:00:00Z', 'end' => '2026-11-11T15:30:00Z']] : []]],
            ]),
            'www.googleapis.com/calendar/v3/calendars/*' => Http::response([
                'id' => 'evt_123',
                'htmlLink' => 'https://www.google.com/calendar/event?eid=evt_123',
            ]),
        ]);
    }

    private function makeServiceAccountFile(): string
    {
        // A fixed test-only key: generating one at runtime needs an OpenSSL config,
        // which some PHP installs (such as XAMPP on Windows) don't have.
        $privateKey = file_get_contents(base_path('tests/fixtures/test-service-account-key.pem'));

        $path = tempnam(sys_get_temp_dir(), 'gsa').'.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'client_email' => 'call-assistant@demo-project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        return $path;
    }
}

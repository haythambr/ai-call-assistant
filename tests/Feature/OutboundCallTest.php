<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Call;
use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundCallTest extends TestCase
{
    use RefreshDatabase;

    // In demo mode the conversation that comes back depends on the number's last digit.
    private const BOOKS_A_VISIT = '+962791112236';

    private const ASKS_NOT_TO_BE_CALLED = '+962791112239';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T10:00:00-05:00'));

        config([
            'call_assistant.timezone' => 'America/New_York',
            'call_assistant.webhook_secret' => 'test-webhook-secret',
            'call_assistant.openai.key' => null,
            'call_assistant.google_calendar.calendar_id' => null,
            'call_assistant.outbound.vapi_key' => null,
            'call_assistant.outbound.calling_hours' => ['start' => '08:00', 'end' => '21:00'],
        ]);

        $this->user = User::factory()->create(['business_name' => 'Amman Smile Clinic']);
        $this->actingAs($this->user);
    }

    public function test_calling_a_number_needs_consent_and_a_valid_number(): void
    {
        $this->postJson('/calls/call-number', ['phone' => self::BOOKS_A_VISIT, 'purpose' => 'Appointment reminder'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        $this->postJson('/calls/call-number', ['phone' => '12345', 'purpose' => 'Appointment reminder', 'consent' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_a_demo_call_comes_back_analysed_and_books_the_visit(): void
    {
        $this->postJson('/calls/call-number', [
            'name' => 'Rami Haddad',
            'phone' => '+962 79 111 2236',
            'purpose' => 'Book a routine check-up',
            'consent' => 1,
        ])->assertCreated();

        // The sync queue runs the delayed demo outcome straight away.
        $call = Call::with('appointment', 'contact')->sole();

        $this->assertSame($this->user->id, $call->user_id);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame('Book a routine check-up', $call->purpose);
        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertSame('book_appointment', $call->intent);
        $this->assertSame(Appointment::STATUS_BOOKED, $call->appointment->status);
        $this->assertSame(self::BOOKS_A_VISIT, $call->contact->phone);
        $this->assertSame($this->user->id, $call->contact->user_id);
        $this->assertNotNull($call->contact->last_called_at);
        $this->assertStringContainsString('Amman Smile Clinic', $call->transcript[0]['text']);
    }

    public function test_calling_a_saved_contact_opens_the_call_desk_on_that_call(): void
    {
        $contact = Contact::addOrUpdate($this->user, ['name' => 'Rami', 'phone' => self::BOOKS_A_VISIT], true);

        $response = $this->post("/contacts/{$contact->id}/call", ['purpose' => 'Appointment reminder']);

        $call = Call::sole();
        $response->assertRedirect("/calls?call={$call->id}");
        $this->assertSame($contact->id, $call->contact_id);
    }

    public function test_someone_who_asks_not_to_be_called_is_never_called_again(): void
    {
        $this->postJson('/calls/call-number', [
            'name' => 'Omar',
            'phone' => self::ASKS_NOT_TO_BE_CALLED,
            'purpose' => 'Appointment reminder',
            'consent' => 1,
        ])->assertCreated();

        $contact = Contact::sole();
        $this->assertNotNull($contact->opted_out_at);
        $this->assertSame('opt_out', Call::sole()->intent);
        $this->assertNull(Call::sole()->appointment);

        $this->post("/contacts/{$contact->id}/call", ['purpose' => 'Appointment reminder'])
            ->assertSessionHasErrors(['call' => 'Omar asked not to be called again.']);

        // Re-adding the number with consent does not undo the opt-out.
        $this->postJson('/calls/call-number', [
            'phone' => self::ASKS_NOT_TO_BE_CALLED,
            'purpose' => 'Appointment reminder',
            'consent' => 1,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('calls', 1);
    }

    public function test_no_calls_go_out_outside_calling_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10T22:30:00-05:00'));

        $this->postJson('/calls/call-number', [
            'phone' => self::BOOKS_A_VISIT,
            'purpose' => 'Appointment reminder',
            'consent' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'between 8:00 AM and 9:00 PM'));

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_a_real_call_is_placed_through_vapi_and_completed_by_the_webhook(): void
    {
        config([
            'call_assistant.outbound.vapi_key' => 'vapi-test-key',
            'call_assistant.outbound.vapi_assistant_id' => 'asst_123',
            'call_assistant.outbound.vapi_phone_number_id' => 'pn_456',
        ]);
        Http::fake(['api.vapi.ai/call' => Http::response(['id' => 'vapi_call_789', 'status' => 'queued'], 201)]);

        $this->postJson('/calls/call-number', [
            'name' => 'Rami Haddad',
            'phone' => self::BOOKS_A_VISIT,
            'purpose' => 'Appointment reminder',
            'consent' => 1,
        ])->assertCreated()->assertJsonPath('status', Call::STATUS_DIALING);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.vapi.ai/call'
            && $request->hasHeader('Authorization', 'Bearer vapi-test-key')
            && $request['assistantId'] === 'asst_123'
            && $request['phoneNumberId'] === 'pn_456'
            && $request['customer']['number'] === self::BOOKS_A_VISIT
            && $request['assistantOverrides']['variableValues']['purpose'] === 'Appointment reminder'
            && $request['assistantOverrides']['variableValues']['business_name'] === 'Amman Smile Clinic');

        $call = Call::sole();
        $this->assertSame('vapi_call_789', $call->external_id);

        // The finished call comes back to the shared URL: it already knows its account.
        $body = json_encode([
            'call_id' => 'vapi_call_789',
            'duration_seconds' => 64,
            'transcript' => [
                ['role' => 'agent', 'text' => 'Hi Rami, this is Ava from Amman Smile Clinic with an appointment reminder.'],
                ['role' => 'caller', 'text' => 'Thanks, see you then.'],
            ],
        ]);
        $this->call('POST', '/api/webhooks/calls', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'test-webhook-secret'),
        ], $body)->assertStatus(202);

        $call->refresh();
        $this->assertSame(Call::STATUS_COMPLETED, $call->status);
        $this->assertSame($this->user->id, $call->user_id);
        $this->assertSame('Rami Haddad', $call->caller_name);
        $this->assertCount(2, $call->transcript);
        $this->assertDatabaseCount('calls', 1);
    }

    public function test_a_refused_vapi_call_is_recorded_as_failed(): void
    {
        config([
            'call_assistant.outbound.vapi_key' => 'vapi-test-key',
            'call_assistant.outbound.vapi_assistant_id' => 'asst_123',
            'call_assistant.outbound.vapi_phone_number_id' => 'pn_456',
        ]);
        Http::fake(['api.vapi.ai/call' => Http::response(['message' => 'Insufficient credits'], 402)]);

        $this->postJson('/calls/call-number', [
            'phone' => self::BOOKS_A_VISIT,
            'purpose' => 'Appointment reminder',
            'consent' => 1,
        ])->assertCreated()->assertJsonPath('status', Call::STATUS_FAILED);

        $this->assertStringStartsWith('The call could not be placed', Call::sole()->error);
    }

    public function test_the_call_desk_only_shows_this_accounts_calls(): void
    {
        Call::simulate(User::factory()->create(), processNow: true);
        Call::simulate($this->user, processNow: true);

        $this->get('/calls')->assertOk()->assertSee('Every call, replayed');
        $this->getJson('/calls/feed')->assertOk()->assertJsonPath('stats.total', 1)->assertJsonCount(1, 'calls');

        $theirs = Call::where('user_id', '!=', $this->user->id)->sole();
        $this->getJson("/calls/{$theirs->id}")->assertNotFound();
    }

    public function test_the_calling_settings_endpoint(): void
    {
        $this->getJson('/calls/settings')
            ->assertOk()
            ->assertJsonPath('calling_hours.open', true)
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('purposes.0', 'Appointment reminder');
    }
}

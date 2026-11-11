<?php

namespace Tests\Feature;

use App\Jobs\RunCampaign;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T10:00:00-05:00'));

        config([
            'call_assistant.timezone' => 'America/New_York',
            'call_assistant.openai.key' => null,
            'call_assistant.google_calendar.calendar_id' => null,
            'call_assistant.outbound.vapi_key' => null,
            'call_assistant.outbound.calling_hours' => ['start' => '08:00', 'end' => '21:00'],
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function contact(string $name, string $phone, bool $consent = true, array $tags = []): Contact
    {
        return Contact::addOrUpdate($this->user, ['name' => $name, 'phone' => $phone], $consent, $tags);
    }

    public function test_a_campaign_that_starts_now_calls_everyone_who_can_be_called(): void
    {
        $nadia = $this->contact('Nadia', '+14155550101');
        $tom = $this->contact('Tom', '+14155550102');
        $noConsent = $this->contact('No consent', '+14155550103', consent: false);

        // The sync queue runs every step (and every demo call) right away.
        $this->post('/campaigns', [
            'name' => 'November reminders',
            'purpose' => 'Appointment reminder',
            'audience' => 'selected',
            'contact_ids' => [$nadia->id, $tom->id, $noConsent->id],
            'when' => 'now',
        ])->assertRedirect();

        $campaign = Campaign::sole();
        $this->assertSame(Campaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame($this->user->id, $campaign->user_id);
        $this->assertNotNull($campaign->started_at);

        $this->assertSame(2, $campaign->calls()->count());
        $this->assertTrue($campaign->calls()->get()->every(fn (Call $call) => $call->purpose === 'Appointment reminder' && $call->user_id === $this->user->id));

        $skipped = $campaign->recipients()->where('contact_id', $noConsent->id)->sole();
        $this->assertSame(CampaignRecipient::STATUS_SKIPPED, $skipped->status);
        $this->assertStringContainsString('agreed to be called', $skipped->reason);

        $progress = $campaign->progress();
        $this->assertSame(3, $progress['total']);
        $this->assertSame(2, $progress['called']);
        $this->assertSame(1, $progress['skipped']);
        $this->assertSame(100, $progress['percent']);
        $this->assertSame(2, array_sum($progress['outcomes']));

        $this->get("/campaigns/{$campaign->id}")->assertOk()->assertSee('November reminders')->assertSee('100%');
        $this->get('/campaigns')->assertOk()->assertSee('November reminders');
    }

    public function test_a_campaign_can_target_a_tag_or_everyone(): void
    {
        Queue::fake();
        $this->contact('Nadia', '+14155550101', tags: ['vip']);
        $this->contact('Tom', '+14155550102', tags: ['recall']);
        $this->contact('No consent', '+14155550103', consent: false, tags: ['vip']);

        $this->post('/campaigns', ['name' => 'VIPs', 'purpose' => 'Hello', 'audience' => 'tag', 'tag' => 'vip', 'when' => 'now']);
        $this->post('/campaigns', ['name' => 'All', 'purpose' => 'Hello', 'audience' => 'all', 'when' => 'now']);

        [$vips, $all] = Campaign::orderBy('id')->get();
        $this->assertSame(2, $vips->recipients()->count());
        $this->assertSame(1, $vips->recipients()->where('status', 'pending')->count());
        $this->assertSame(2, $all->recipients()->count(), 'Everyone means everyone with consent.');
    }

    public function test_a_scheduled_campaign_waits_for_its_start_time(): void
    {
        Queue::fake();
        $this->contact('Nadia', '+14155550101');

        $this->post('/campaigns', [
            'name' => 'Tomorrow morning',
            'purpose' => 'Book a routine check-up',
            'audience' => 'all',
            'when' => 'later',
            'scheduled_at' => '2026-11-11T09:30',
        ])->assertRedirect();

        $campaign = Campaign::sole();
        $this->assertSame(Campaign::STATUS_SCHEDULED, $campaign->status);
        $this->assertSame('2026-11-11T09:30:00-05:00', $campaign->scheduledLocal()->toIso8601String());
        $this->assertSame(0, Call::count());

        Queue::assertPushed(RunCampaign::class, fn (RunCampaign $job) => $job->campaign->is($campaign)
            && $job->delay->equalTo($campaign->scheduled_at));

        // A step that fires early just waits again.
        $campaign->runNextStep($campaign->run_token);
        $this->assertSame(Campaign::STATUS_SCHEDULED, $campaign->fresh()->status);

        // At the start time it begins calling.
        $this->travelTo(CarbonImmutable::parse('2026-11-11T09:30:00-05:00'));
        $campaign->runNextStep($campaign->run_token);
        $this->assertSame(Campaign::STATUS_RUNNING, $campaign->fresh()->status);
    }

    public function test_a_schedule_in_the_past_is_refused(): void
    {
        $this->contact('Nadia', '+14155550101');

        $this->post('/campaigns', [
            'name' => 'Too late',
            'purpose' => 'Hello',
            'audience' => 'all',
            'when' => 'later',
            'scheduled_at' => '2026-11-09T09:30',
        ])->assertSessionHasErrors('scheduled_at');

        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_a_campaign_needs_someone_to_call(): void
    {
        $this->post('/campaigns', ['name' => 'Empty', 'purpose' => 'Hello', 'audience' => 'all', 'when' => 'now'])
            ->assertSessionHasErrors('audience');
        $this->post('/campaigns', ['name' => 'Empty', 'purpose' => 'Hello', 'audience' => 'selected', 'when' => 'now'])
            ->assertSessionHasErrors('contact_ids');
    }

    public function test_pausing_stops_calls_and_resuming_picks_up_where_it_stopped(): void
    {
        Queue::fake();
        foreach (['01', '02', '03'] as $n) {
            $this->contact("Person {$n}", "+141555501{$n}");
        }

        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', $this->user->contacts()->get());
        $firstToken = $campaign->run_token;

        $campaign->runNextStep($firstToken);
        $this->assertSame(1, $campaign->calls()->count());

        $this->post("/campaigns/{$campaign->id}/pause")->assertSessionHas('status');
        $this->assertSame(Campaign::STATUS_PAUSED, $campaign->fresh()->status);

        // A step queued before the pause does nothing.
        $campaign->runNextStep($firstToken);
        $this->assertSame(1, $campaign->calls()->count());

        $this->post("/campaigns/{$campaign->id}/resume");
        $campaign->refresh();
        $this->assertSame(Campaign::STATUS_RUNNING, $campaign->status);
        $this->assertNotSame($firstToken, $campaign->run_token);

        // The old chain stays dead; the new one carries on.
        $campaign->runNextStep($firstToken);
        $this->assertSame(1, $campaign->calls()->count());
        $campaign->runNextStep($campaign->run_token);
        $campaign->runNextStep($campaign->run_token);
        $campaign->runNextStep($campaign->run_token);

        $this->assertSame(3, $campaign->calls()->count());
        $this->assertSame(Campaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_cancelling_skips_everyone_not_called_yet(): void
    {
        Queue::fake();
        $this->contact('A', '+14155550101');
        $this->contact('B', '+14155550102');

        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', $this->user->contacts()->get());
        $campaign->runNextStep($campaign->run_token);

        $this->post("/campaigns/{$campaign->id}/cancel");

        $campaign->refresh();
        $this->assertSame(Campaign::STATUS_CANCELLED, $campaign->status);
        $this->assertSame(1, $campaign->recipients()->where('status', 'called')->count());
        $this->assertSame(1, $campaign->recipients()->where('status', 'skipped')->count());

        // Once ended it can be deleted, but a running one cannot.
        $this->delete("/campaigns/{$campaign->id}")->assertRedirect('/campaigns');
        $this->assertModelMissing($campaign);
    }

    public function test_outside_calling_hours_the_campaign_waits_instead_of_skipping(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T22:30:00-05:00'));
        $this->contact('Nadia', '+14155550101');

        $campaign = Campaign::launch($this->user, 'Late', 'Appointment reminder', $this->user->contacts()->get());
        $campaign->runNextStep($campaign->run_token);

        $this->assertSame(0, Call::count());
        $this->assertSame(1, $campaign->recipients()->where('status', 'pending')->count());
        Queue::assertPushed(RunCampaign::class, fn (RunCampaign $job) => $job->delay instanceof \DateTimeInterface
            && CarbonImmutable::instance($job->delay)->equalTo(CarbonImmutable::parse('2026-11-11T08:00:00-05:00')));
    }

    public function test_a_stalled_campaign_picks_up_again_when_its_page_is_opened(): void
    {
        Queue::fake();
        $this->contact('A', '+14155550101');
        $this->contact('B', '+14155550102');
        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', $this->user->contacts()->get());
        $lostToken = $campaign->run_token;

        // Recently active: left alone.
        $this->get("/campaigns/{$campaign->id}")->assertOk();
        $this->assertSame($lostToken, $campaign->fresh()->run_token);

        // Silent for a while (say the worker was down): a fresh chain starts.
        $this->travel(10)->minutes();
        $this->get("/campaigns/{$campaign->id}")->assertOk();
        $this->assertNotSame($lostToken, $campaign->fresh()->run_token);
        Queue::assertPushed(RunCampaign::class, 2);
    }

    public function test_a_step_that_is_retried_never_rings_the_same_person_twice(): void
    {
        Queue::fake();
        $this->contact('A', '+14155550101');
        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', $this->user->contacts()->get());

        $campaign->runNextStep($campaign->run_token);
        $campaign->runNextStep($campaign->run_token); // the same step run again

        $this->assertSame(1, Call::count());
        $this->assertSame(Campaign::STATUS_COMPLETED, $campaign->fresh()->status);
    }

    public function test_someone_who_opts_out_during_a_campaign_is_skipped_later(): void
    {
        Queue::fake();
        $nadia = $this->contact('Nadia', '+14155550101');
        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', $this->user->contacts()->get());

        $nadia->optOut();
        $campaign->runNextStep($campaign->run_token);

        $this->assertSame(0, Call::count());
        $this->assertSame(CampaignRecipient::STATUS_SKIPPED, $campaign->recipients()->sole()->status);
    }

    public function test_other_accounts_campaigns_are_out_of_reach(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $theirs = Campaign::launch($other, 'Theirs', 'Hello', collect([Contact::addOrUpdate($other, ['phone' => '+14155550101'], true)]));

        $this->get("/campaigns/{$theirs->id}")->assertNotFound();
        $this->post("/campaigns/{$theirs->id}/pause")->assertNotFound();
        $this->get('/campaigns')->assertDontSee('Theirs');

        // Contacts from another account can't be slipped into a campaign either.
        $this->post('/campaigns', [
            'name' => 'Sneaky',
            'purpose' => 'Hello',
            'audience' => 'selected',
            'contact_ids' => $other->contacts()->pluck('id')->all(),
            'when' => 'now',
        ])->assertSessionHasErrors('audience');
    }

    public function test_the_live_part_of_the_campaign_page_can_be_fetched_alone(): void
    {
        Queue::fake();
        $this->contact('Nadia', '+14155550101');
        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', $this->user->contacts()->get());

        $html = $this->get("/campaigns/{$campaign->id}?fragment=1")->assertOk()->getContent();

        $this->assertStringStartsWith('<div id="campaign-live"', trim($html));
        $this->assertStringNotContainsString('<html', $html);
    }

    public function test_the_new_campaign_page_can_start_from_selected_contacts_or_a_past_campaign(): void
    {
        Queue::fake();
        $nadia = $this->contact('Nadia', '+14155550101');
        $this->get('/campaigns/new?contacts[]='.$nadia->id)->assertOk()->assertSee('Nadia');

        $campaign = Campaign::launch($this->user, 'Recall', 'Appointment reminder', collect([$nadia]));
        $this->get("/campaigns/new?from={$campaign->id}")->assertOk()->assertSee('Run “Recall” again', false);
    }
}

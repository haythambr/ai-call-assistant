<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /* Import --------------------------------------------------------------- */

    public function test_a_csv_of_contacts_is_imported_and_bad_rows_are_reported(): void
    {
        $csv = "\xEF\xBB\xBFName,Phone Number,Email,Tags\n"
            ."Nadia Karim,+962 79 123 4567,nadia@example.com,\"VIP, recall\"\n"
            ."No Country Code,0791234567,,\n"
            ."Tom Becker,+1 (415) 555-0177,not-an-email,\n";

        $this->post('/contacts/import', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
            'tags' => 'november',
            'consent' => '1',
        ])
            ->assertRedirect('/contacts/import')
            ->assertSessionHas('import_report', fn (array $report) => $report['imported'] === 2
                && $report['updated'] === 0
                && $report['skipped'][0]['row'] === 3
                && $report['file'] === 'contacts.csv');

        $nadia = $this->user->contacts()->where('phone', '+962791234567')->sole();
        $this->assertSame('Nadia Karim', $nadia->name);
        $this->assertNotNull($nadia->consent_at);
        $this->assertSame(['november', 'vip', 'recall'], $nadia->tags);
        $this->assertNull($this->user->contacts()->where('phone', '+14155550177')->sole()->email);
    }

    public function test_importing_again_updates_instead_of_duplicating(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('c.csv', "name;mobile\nPriya Nair;+44 7700 900123\n");

        $this->post('/contacts/import', ['file' => $file(), 'consent' => '1']);
        $this->post('/contacts/import', ['file' => $file(), 'consent' => '1'])
            ->assertSessionHas('import_report', fn (array $report) => $report['imported'] === 0 && $report['updated'] === 1);

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseHas('contacts', ['phone' => '+447700900123', 'name' => 'Priya Nair', 'user_id' => $this->user->id]);
    }

    public function test_importing_requires_consent_and_a_phone_column(): void
    {
        $this->post('/contacts/import', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,phone\nA,+962791234567\n"),
        ])->assertSessionHasErrors('consent');

        $this->post('/contacts/import', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nA,a@example.com\n"),
            'consent' => '1',
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_the_same_number_can_belong_to_two_accounts(): void
    {
        $other = User::factory()->create();
        Contact::addOrUpdate($other, ['name' => 'Theirs', 'phone' => '+962791234567'], true);
        Contact::addOrUpdate($this->user, ['name' => 'Mine', 'phone' => '+962791234567'], true);

        $this->assertDatabaseCount('contacts', 2);
        $this->assertSame('Mine', $this->user->contacts()->sole()->name);
    }

    public function test_phone_numbers_are_normalised(): void
    {
        $this->assertSame('+962791234567', Contact::normalizePhone('+962 79 123 4567'));
        $this->assertSame('+962791234567', Contact::normalizePhone('00962791234567'));
        $this->assertSame('+14155550142', Contact::normalizePhone('+1 (415) 555-0142'));
        $this->assertNull(Contact::normalizePhone('0791234567'));
        $this->assertNull(Contact::normalizePhone('+12'));

        config(['call_assistant.outbound.default_country_code' => '962']);
        $this->assertSame('+962791234567', Contact::normalizePhone('0791234567'));
    }

    /* Create, read, update, delete ------------------------------------------ */

    public function test_a_contact_is_created_edited_and_deleted(): void
    {
        $this->post('/contacts', [
            'name' => 'Sarah Mitchell',
            'phone' => '+1 415 555 0142',
            'email' => 'sarah@example.com',
            'tags' => 'VIP, recall, vip',
            'notes' => 'Prefers mornings',
            'consent' => '1',
        ])->assertRedirect();

        $contact = $this->user->contacts()->sole();
        $this->assertSame('+14155550142', $contact->phone);
        $this->assertSame(['vip', 'recall'], $contact->tags);
        $this->assertNotNull($contact->consent_at);

        $this->get("/contacts/{$contact->id}")->assertOk()->assertSee('Sarah Mitchell')->assertSee('Prefers mornings');
        $this->get("/contacts/{$contact->id}/edit")->assertOk();

        // Unticking consent withdraws it.
        $this->put("/contacts/{$contact->id}", ['name' => 'Sarah M.', 'phone' => '+14155550142'])->assertRedirect();
        $contact->refresh();
        $this->assertSame('Sarah M.', $contact->name);
        $this->assertNull($contact->consent_at);
        $this->assertSame([], $contact->tags);

        $this->delete("/contacts/{$contact->id}")->assertRedirect('/contacts');
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_two_contacts_cannot_share_a_number(): void
    {
        Contact::addOrUpdate($this->user, ['name' => 'Nadia', 'phone' => '+962791234567'], true);

        $this->post('/contacts', ['phone' => '00962 79 123 4567'])->assertSessionHasErrors('phone');
        $this->post('/contacts', ['phone' => '12345'])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        Contact::addOrUpdate($this->user, ['name' => 'Nadia Karim', 'phone' => '+962791234567'], true, ['vip']);
        Contact::addOrUpdate($this->user, ['name' => 'Tom Becker', 'phone' => '+14155550102'], false);
        Contact::addOrUpdate($this->user, ['name' => 'Omar Opted', 'phone' => '+14155550103'], true)->optOut();

        $this->get('/contacts')->assertOk()->assertSee('Nadia Karim')->assertSee('Tom Becker')->assertSee('Omar Opted');
        $this->get('/contacts?q=nadia')->assertSee('Nadia Karim')->assertDontSee('Tom Becker');
        $this->get('/contacts?q=555010')->assertSee('Tom Becker')->assertDontSee('Nadia Karim');
        $this->get('/contacts?tag=vip')->assertSee('Nadia Karim')->assertDontSee('Tom Becker');
        $this->get('/contacts?status=no_consent')->assertSee('Tom Becker')->assertDontSee('Nadia Karim');
        $this->get('/contacts?status=opted_out')->assertSee('Omar Opted')->assertDontSee('Tom Becker');
    }

    public function test_other_accounts_contacts_are_out_of_reach(): void
    {
        $theirs = Contact::addOrUpdate(User::factory()->create(), ['name' => 'Private', 'phone' => '+962791234567'], true);

        $this->get("/contacts/{$theirs->id}")->assertNotFound();
        $this->put("/contacts/{$theirs->id}", ['phone' => '+962791234567'])->assertNotFound();
        $this->delete("/contacts/{$theirs->id}")->assertNotFound();
        $this->get('/contacts')->assertDontSee('Private');

        $this->delete('/contacts', ['contact_ids' => [$theirs->id]])->assertRedirect();
        $this->assertModelExists($theirs);
    }

    public function test_selected_contacts_are_deleted_together(): void
    {
        $a = Contact::addOrUpdate($this->user, ['phone' => '+14155550101'], true);
        $b = Contact::addOrUpdate($this->user, ['phone' => '+14155550102'], true);
        $keep = Contact::addOrUpdate($this->user, ['phone' => '+14155550103'], true);

        $this->delete('/contacts', ['contact_ids' => [$a->id, $b->id]])->assertSessionHas('status', '2 contacts were deleted.');

        $this->assertSame([$keep->id], $this->user->contacts()->pluck('id')->all());
    }

    public function test_contacts_export_as_a_csv_that_imports_again(): void
    {
        Contact::addOrUpdate($this->user, ['name' => 'Nadia Karim', 'phone' => '+962791234567', 'email' => 'nadia@example.com'], true, ['vip']);

        $csv = $this->get('/contacts/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('name,phone,email,tags,notes,status,last_called_at', $csv);
        $this->assertStringContainsString('"Nadia Karim",+962791234567,nadia@example.com,vip', $csv);
    }
}

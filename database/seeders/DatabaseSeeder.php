<?php

namespace Database\Seeders;

use App\Models\Call;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * A demo account with a few processed sample calls and example contacts.
     * The numbers are in the 555-01XX range, which is reserved for fiction
     * and never reaches a real person.
     */
    public function run(): void
    {
        $demo = config('call_assistant.demo_account') ?? [
            'email' => 'demo@callassistant.test',
            'password' => 'demo-password',
        ];

        $user = User::firstOrCreate(['email' => $demo['email']], [
            'name' => 'Dana Demo',
            'business_name' => config('call_assistant.business_name'),
            'password' => $demo['password'],
        ]);

        if ($user->calls()->doesntExist()) {
            for ($i = 0; $i < 3; $i++) {
                Call::simulate($user, processNow: true);
            }
        }

        foreach ([
            ['name' => 'Nadia Karim', 'phone' => '+1 415 555 0101', 'email' => 'nadia@example.com', 'tags' => ['recall', 'vip']],
            ['name' => 'Tom Becker', 'phone' => '+1 415 555 0102', 'tags' => ['recall']],
            ['name' => 'Priya Nair', 'phone' => '+1 415 555 0103', 'email' => 'priya@example.com', 'tags' => ['new-patient']],
            ['name' => 'Omar Haddad', 'phone' => '+1 415 555 0104', 'tags' => ['recall']],
            ['name' => 'Grace Liu', 'phone' => '+1 415 555 0105', 'email' => 'grace@example.com', 'tags' => ['new-patient', 'vip']],
            ['name' => 'Marco Rossi', 'phone' => '+1 415 555 0106', 'tags' => []],
        ] as $contact) {
            Contact::addOrUpdate($user, $contact, consentGiven: true, addTags: $contact['tags']);
        }

        Contact::addOrUpdate($user, ['name' => 'Leah Brooks', 'phone' => '+1 415 555 0107'], consentGiven: false);
    }
}

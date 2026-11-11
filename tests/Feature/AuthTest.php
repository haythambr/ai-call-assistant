<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['/', '/contacts', '/calls', '/campaigns', '/settings'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $this->getJson('/calls/feed')->assertUnauthorized();
    }

    public function test_the_sign_in_page_offers_the_demo_account(): void
    {
        $this->get('/login')->assertOk()->assertSee('Try the demo account')->assertSee('demo@callassistant.test');

        config(['call_assistant.demo_account' => null]);
        $this->get('/login')->assertOk()->assertDontSee('Try the demo account');
    }

    public function test_signing_up_creates_the_account_and_signs_in(): void
    {
        $this->post('/register', [
            'name' => 'Haya Saleh',
            'business_name' => 'Amman Smile Clinic',
            'email' => 'haya@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect('/');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Amman Smile Clinic', $user->businessName());
        $this->assertSame(40, strlen($user->webhook_token));

        $this->get('/')->assertOk()->assertSee('Haya')->assertSee('Amman Smile Clinic');
    }

    public function test_sign_up_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => '',
            'business_name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['name', 'business_name', 'email', 'password']);

        $this->assertGuest();
    }

    public function test_signing_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'dana@example.com', 'password' => 'correct-password']);

        $this->post('/login', ['email' => 'dana@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'dana@example.com', 'password' => 'correct-password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_signed_in_users_skip_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_settings_update_the_business_name_and_rotate_the_webhook_url(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->webhook_token;

        $this->actingAs($user)->put('/settings', [
            'name' => 'Dana Demo',
            'business_name' => 'Bright Teeth',
            'email' => $user->email,
        ])->assertRedirect();
        $this->assertSame('Bright Teeth', $user->fresh()->business_name);

        $this->post('/settings/webhook-token')->assertRedirect();
        $this->assertNotSame($oldToken, $user->fresh()->webhook_token);
    }
}

<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * An account: one business whose AI receptionist makes and takes calls.
 * Everything else (contacts, calls, campaigns) belongs to a user.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'business_name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->webhook_token ??= static::newWebhookToken();
        });
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function businessName(): string
    {
        return $this->business_name ?: config('call_assistant.business_name');
    }

    public function firstName(): string
    {
        return Str::before(trim($this->name), ' ') ?: $this->name;
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * Where the voice provider should send this account's finished calls.
     */
    public function webhookUrl(): string
    {
        return route('webhooks.calls', ['token' => $this->webhook_token]);
    }

    public function rotateWebhookToken(): void
    {
        $this->forceFill(['webhook_token' => static::newWebhookToken()])->save();
    }

    protected static function newWebhookToken(): string
    {
        return Str::lower(Str::random(40));
    }

    /**
     * Distinct tags used on this account's contacts, with how many have each.
     *
     * @return array<string, int>
     */
    public function contactTags(): array
    {
        return $this->contacts()->whereNotNull('tags')->pluck('tags')
            ->flatten()
            ->countBy()
            ->sortKeys()
            ->all();
    }
}

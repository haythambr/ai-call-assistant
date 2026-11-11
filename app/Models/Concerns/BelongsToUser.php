<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For records owned by an account. Route model binding only finds records
 * that belong to the signed-in user, so another account's ID is a 404.
 */
trait BelongsToUser
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $user->id);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->when(auth()->check(), fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->firstOrFail();
    }
}

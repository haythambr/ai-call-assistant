<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a campaign's list: waiting, called (with the call), or
 * skipped (with the reason).
 */
class CampaignRecipient extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CALLED = 'called';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'campaign_contact';

    protected $fillable = ['campaign_id', 'contact_id', 'status', 'call_id', 'reason'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /**
     * What to show in the results table for this person.
     */
    public function resultKey(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'pending',
            self::STATUS_SKIPPED => 'skipped',
            default => $this->call?->outcome() ?? 'in_progress',
        };
    }
}

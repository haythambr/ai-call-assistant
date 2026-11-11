<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileObject;

/**
 * A person an account may call. Owns everything about who can be dialed:
 * consent, opt-outs, calling hours, tags and CSV import/export.
 */
class Contact extends Model
{
    use BelongsToUser;

    public const STATUS_CALLABLE = 'callable';

    public const STATUS_NO_CONSENT = 'no_consent';

    public const STATUS_OPTED_OUT = 'opted_out';

    public const MAX_TAGS = 10;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'notes',
        'tags',
        'consent_at',
        'opted_out_at',
        'last_called_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'consent_at' => 'immutable_datetime',
            'opted_out_at' => 'immutable_datetime',
            'last_called_at' => 'immutable_datetime',
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot(['status', 'call_id', 'reason'])->withTimestamps();
    }

    /* ------------------------------------------------------------------
     | Queries
     | ------------------------------------------------------------------ */

    public function scopeCallable(Builder $query): Builder
    {
        return $query->whereNotNull('consent_at')->whereNull('opted_out_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $term);

        return $query->where(function (Builder $query) use ($term, $digits) {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");

            if (strlen($digits) >= 3) {
                $query->orWhere('phone', 'like', "%{$digits}%");
            }
        });
    }

    public function scopeTagged(Builder $query, ?string $tag): Builder
    {
        return filled($tag) ? $query->whereJsonContains('tags', $tag) : $query;
    }

    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_CALLABLE => $query->callable(),
            self::STATUS_NO_CONSENT => $query->whereNull('consent_at')->whereNull('opted_out_at'),
            self::STATUS_OPTED_OUT => $query->whereNotNull('opted_out_at'),
            default => $query,
        };
    }

    /* ------------------------------------------------------------------
     | Adding and editing contacts
     | ------------------------------------------------------------------ */

    /**
     * Create or update one of the account's contacts by phone number, as an
     * import or a quick call does. Consent is only ever added here, and an
     * opt-out is kept even if the person is added again.
     *
     * @param  array<int, string>  $addTags
     */
    public static function addOrUpdate(User $user, array $data, bool $consentGiven, array $addTags = []): self
    {
        $phone = static::normalizePhone($data['phone'] ?? '');

        if ($phone === null) {
            throw new InvalidArgumentException('Enter the number in international format, for example +1 415 555 0142.');
        }

        $contact = $user->contacts()->firstOrNew(['phone' => $phone]);
        $contact->fill(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'email' => isset($data['email']) ? trim($data['email']) : null,
            'notes' => isset($data['notes']) ? trim($data['notes']) : null,
        ], fn ($value) => filled($value)));

        if ($addTags !== []) {
            $contact->tags = static::normalizeTags(array_merge($contact->tags ?? [], $addTags));
        }

        if ($consentGiven && ! $contact->consent_at) {
            $contact->consent_at = now();
        }

        $contact->save();

        return $contact;
    }

    /**
     * Save the add/edit contact form. Unlike an import, the form is the whole
     * truth: every field is replaced, and consent can be withdrawn.
     *
     * @param  array{name: ?string, phone: string, email: ?string, notes: ?string, tags: ?string, consent: bool}  $data
     */
    public static function saveFromForm(User $user, array $data, ?self $contact = null): self
    {
        $phone = static::normalizePhone($data['phone']);

        if ($phone === null) {
            throw new InvalidArgumentException('Enter the number in international format, for example +1 415 555 0142.');
        }

        $duplicate = $user->contacts()->where('phone', $phone)
            ->when($contact, fn (Builder $query) => $query->whereKeyNot($contact->id))
            ->first();

        if ($duplicate) {
            throw new InvalidArgumentException("{$duplicate->label()} already has this number.");
        }

        $contact ??= $user->contacts()->make();
        $contact->fill([
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
            'phone' => $phone,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'tags' => static::normalizeTags(static::parseTags($data['tags'] ?? '')),
        ]);

        if ($data['consent']) {
            $contact->consent_at ??= now();
        } else {
            $contact->consent_at = null;
        }

        $contact->save();

        return $contact;
    }

    /**
     * Import contacts from a CSV with a header row. Recognised columns:
     * name, phone (or "phone number", "mobile", "number"), email, notes, tags.
     *
     * @param  array<int, string>  $addTags  tags put on every imported contact
     * @return array{imported: int, updated: int, skipped: array<int, array{row: int, reason: string}>}
     */
    public static function importCsv(User $user, string $path, bool $consentGiven, array $addTags = []): array
    {
        $file = new SplFileObject($path);
        $firstLine = (string) $file->fgets();
        $file->rewind();

        // Spreadsheets saved in some regions use semicolons instead of commas.
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '\\');

        $columns = null;
        $report = ['imported' => 0, 'updated' => 0, 'skipped' => []];
        $rowNumber = 0;

        foreach ($file as $row) {
            $rowNumber++;

            if ($row === [null] || $row === false) {
                continue;
            }

            if ($columns === null) {
                $columns = static::mapCsvColumns($row);

                if (! isset($columns['phone'])) {
                    throw new InvalidArgumentException('The CSV needs a "phone" column in its first row.');
                }

                continue;
            }

            if ($rowNumber > 5001) {
                $report['skipped'][] = ['row' => $rowNumber, 'reason' => 'Only the first 5,000 contacts are imported at once.'];
                break;
            }

            $data = [];
            foreach ($columns as $field => $index) {
                $data[$field] = isset($row[$index]) ? trim((string) $row[$index]) : null;
            }

            $phone = static::normalizePhone($data['phone'] ?? '');

            if ($phone === null) {
                $report['skipped'][] = ['row' => $rowNumber, 'reason' => 'Phone number "'.Str::limit((string) ($data['phone'] ?? ''), 30).'" is not valid.'];

                continue;
            }

            if (filled($data['email'] ?? null) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $data['email'] = null;
            }

            $tags = array_merge($addTags, static::parseTags($data['tags'] ?? ''));
            $existed = $user->contacts()->where('phone', $phone)->exists();
            static::addOrUpdate($user, $data, $consentGiven, $tags);
            $existed ? $report['updated']++ : $report['imported']++;
        }

        if ($columns === null) {
            throw new InvalidArgumentException('The file is empty.');
        }

        return $report;
    }

    /**
     * @return array<string, int> field => column index
     */
    protected static function mapCsvColumns(array $header): array
    {
        $aliases = [
            'name' => ['name', 'full name', 'contact', 'contact name', 'patient', 'customer'],
            'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'number', 'tel', 'telephone'],
            'email' => ['email', 'e-mail', 'email address'],
            'notes' => ['notes', 'note', 'comments'],
            'tags' => ['tags', 'tag', 'labels', 'group', 'groups', 'list'],
        ];

        $columns = [];
        foreach ($header as $index => $title) {
            // Strip a UTF-8 BOM that Excel adds to the first cell.
            $title = Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $title)));

            foreach ($aliases as $field => $names) {
                if (! isset($columns[$field]) && in_array($title, $names, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        return $columns;
    }

    /**
     * Rows for a CSV export that can be imported again as it is.
     *
     * @return iterable<int, array<int, string>>
     */
    public static function exportRows(User $user): iterable
    {
        yield ['name', 'phone', 'email', 'tags', 'notes', 'status', 'last_called_at'];

        foreach ($user->contacts()->orderBy('name')->lazy() as $contact) {
            yield [
                (string) $contact->name,
                $contact->phone,
                (string) $contact->email,
                implode(', ', $contact->tags ?? []),
                (string) $contact->notes,
                $contact->statusLabel(),
                (string) $contact->last_called_at?->toDateTimeString(),
            ];
        }
    }

    /**
     * Turn a typed or spreadsheet number into E.164 (+ and 8 to 15 digits),
     * or null when it cannot be one.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (Str::startsWith($raw, '+')) {
            $number = $digits;
        } elseif (Str::startsWith($digits, '00')) {
            $number = substr($digits, 2);
        } elseif (filled($code = config('call_assistant.outbound.default_country_code'))) {
            $number = ltrim((string) $code, '+').ltrim($digits, '0');
        } else {
            return null;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $number) ? '+'.$number : null;
    }

    /**
     * @return array<int, string>
     */
    public static function parseTags(?string $raw): array
    {
        return preg_split('/[,;|]/', (string) $raw) ?: [];
    }

    /**
     * Lowercase, trimmed, unique and capped, so "VIP " and "vip" are one tag.
     *
     * @return array<int, string>
     */
    public static function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($tag) => Str::limit(Str::lower(trim((string) $tag)), 30, ''))
            ->filter(fn (string $tag) => $tag !== '')
            ->unique()
            ->take(self::MAX_TAGS)
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------
     | Who can be called, and when
     | ------------------------------------------------------------------ */

    /**
     * Why this contact cannot be called right now, or null when they can.
     */
    public function callBlocker(): ?string
    {
        return $this->permissionBlocker()
            ?? ($this->calls()->where('status', Call::STATUS_DIALING)->exists() ? $this->label().' is already being called.' : null)
            ?? static::callingHoursBlocker();
    }

    /**
     * Reasons that never go away by waiting: no consent, or an opt-out.
     */
    public function permissionBlocker(): ?string
    {
        if ($this->opted_out_at) {
            return $this->label().' asked not to be called again.';
        }

        if (! $this->consent_at) {
            return 'There is no record that '.$this->label().' agreed to be called.';
        }

        return null;
    }

    public static function callingHoursBlocker(): ?string
    {
        [$now, $start, $end] = static::callingWindowToday();

        if ($now->lt($start) || $now->gte($end)) {
            return 'Calls can only go out between '.$start->format('g:i A').' and '.$end->format('g:i A').' ('.$now->timezoneName.'). It is '.$now->format('g:i A').' now.';
        }

        return null;
    }

    /**
     * When calls may next go out: now if calling hours are open, otherwise
     * the next opening.
     */
    public static function nextCallingTime(): CarbonImmutable
    {
        [$now, $start, $end] = static::callingWindowToday();

        return match (true) {
            $now->lt($start) => $start,
            $now->gte($end) => $start->addDay(),
            default => $now,
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    protected static function callingWindowToday(): array
    {
        $now = CarbonImmutable::now(config('call_assistant.timezone'));

        return [
            $now,
            $now->setTimeFromTimeString(config('call_assistant.outbound.calling_hours.start')),
            $now->setTimeFromTimeString(config('call_assistant.outbound.calling_hours.end')),
        ];
    }

    public function optOut(): void
    {
        if (! $this->opted_out_at) {
            $this->update(['opted_out_at' => now()]);
        }
    }

    /* ------------------------------------------------------------------
     | Presentation
     | ------------------------------------------------------------------ */

    public function label(): string
    {
        return $this->name ?: $this->displayPhone();
    }

    /**
     * The stored E.164 number spaced out for reading: "+1 415 555 0142",
     * "+962 79 123 4567". Only for display; the stored number never changes.
     */
    public function displayPhone(): string
    {
        return static::formatPhone((string) $this->phone);
    }

    /**
     * Space out any number for reading. Numbers that aren't E.164 are left as they are.
     */
    public static function formatPhone(string $phone): string
    {
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            return $phone;
        }

        $digits = ltrim($phone, '+');

        if (str_starts_with($digits, '1') && strlen($digits) === 11) {
            return '+1 '.substr($digits, 1, 3).' '.substr($digits, 4, 3).' '.substr($digits, 7);
        }

        // Country codes are 1 to 3 digits; these are all the 1- and 2-digit ones (ITU E.164).
        $twoDigit = ['20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49',
            '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86',
            '90', '91', '92', '93', '94', '95', '98'];
        $length = match (true) {
            in_array($digits[0] ?? '', ['1', '7'], true) => 1,
            in_array(substr($digits, 0, 2), $twoDigit, true) => 2,
            default => 3,
        };
        $country = substr($digits, 0, $length);
        $rest = substr($digits, $length);
        $groups = strlen($rest) > 6 ? [substr($rest, 0, 2), substr($rest, 2, 3), substr($rest, 5)] : [$rest];

        return '+'.$country.' '.implode(' ', array_filter($groups, 'strlen'));
    }

    public function status(): string
    {
        return match (true) {
            $this->opted_out_at !== null => self::STATUS_OPTED_OUT,
            $this->consent_at === null => self::STATUS_NO_CONSENT,
            default => self::STATUS_CALLABLE,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            self::STATUS_OPTED_OUT => 'Do not call',
            self::STATUS_NO_CONSENT => 'No consent',
            default => 'Can be called',
        };
    }

    public function initials(): string
    {
        if (blank($this->name)) {
            return '#';
        }

        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * Settings the "call one number" panel needs.
     */
    public static function callingSettings(): array
    {
        return [
            'calling_hours' => [
                'start' => config('call_assistant.outbound.calling_hours.start'),
                'end' => config('call_assistant.outbound.calling_hours.end'),
                'timezone' => config('call_assistant.timezone'),
                'open' => static::callingHoursBlocker() === null,
            ],
            'purposes' => config('call_assistant.outbound.purposes'),
            'demo_mode' => ! Call::usesVapi(),
        ];
    }

    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'tags' => $this->tags ?? [],
            'has_consent' => $this->consent_at !== null,
            'opted_out' => $this->opted_out_at !== null,
            'last_called_at' => $this->last_called_at?->toIso8601String(),
        ];
    }
}

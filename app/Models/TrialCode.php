<?php

namespace App\Models;

use Database\Factories\TrialCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'code',
    'plan_id',
    'trial_days',
    'max_redemptions',
    'redeemed_count',
    'expires_at',
    'owner_user_id',
    'is_active',
    'note',
])]
class TrialCode extends Model
{
    /** @use HasFactory<TrialCodeFactory> */
    use HasFactory;

    /**
     * Ambiguous characters are left out of generated codes: these get read off
     * a screen and typed by hand, and O/0 and I/1 are where that goes wrong.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected function casts(): array
    {
        return [
            'trial_days' => 'integer',
            'max_redemptions' => 'integer',
            'redeemed_count' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The plan the trial runs on. Null falls back to the default trial plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The user whose referral code this is, for a personal code.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The trials this code has granted.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Codes are matched case-insensitively by normalising on the way in and on
     * lookup, so a user typing lowercase still redeems successfully.
     */
    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', self::normalise($code))->first();
    }

    /**
     * A random, unambiguous code. Collisions are astronomically unlikely at
     * this length, and the unique index is the backstop if one ever happens.
     */
    public static function generateCode(int $length = 8, string $prefix = ''): string
    {
        $body = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::normalise($prefix === '' ? $body : $prefix.'-'.$body);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null && $this->redeemed_count >= $this->max_redemptions;
    }

    /**
     * Whether the code could be redeemed by somebody right now. Says nothing
     * about the particular redeemer — {@see TrialRedeemer} checks that.
     */
    public function isRedeemable(): bool
    {
        return $this->is_active && ! $this->hasExpired() && ! $this->isExhausted();
    }

    /**
     * Set a code, generating one when none was supplied.
     */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = self::normalise(
            $value === null || $value === '' ? self::generateCode() : $value,
        );
    }

    /**
     * A referral code derived from the owner's name, so it is recognisable to
     * whoever receives it — "MARIA-K7Q2WXYZ" rather than an opaque string.
     */
    public static function referralPrefixFor(User $user): string
    {
        $first = Str::of($user->name ?? '')->trim()->explode(' ')->first() ?? '';

        $clean = Str::of($first)->ascii()->replaceMatches('/[^A-Za-z]/', '')->upper()->limit(8, '');

        return $clean->isEmpty() ? 'REF' : (string) $clean;
    }
}

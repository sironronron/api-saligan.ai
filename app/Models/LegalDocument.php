<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegalDocument extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_TERMS_PRIVACY = 'terms_privacy';

    protected $fillable = [
        'type',
        'version',
        'title',
        'content',
        'effective_at',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
    ];

    /**
     * Request-scoped memo, so the middleware and UserResource do not each re-query.
     *
     * @var array<string, self|null>
     */
    protected static array $currentMemo = [];

    protected static function booted(): void
    {
        static::saved(function (): void {
            static::$currentMemo = [];
        });
    }

    /**
     * The hash is derived from the content, never set by hand, so an acceptance
     * record can always be checked against the exact text that was accepted.
     *
     * This is a mutator rather than a saving() hook because seeders run inside
     * Model::withoutEvents() (see DatabaseSeeder), which would skip the hook and
     * leave the not-null hash column empty.
     */
    protected function content(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): array => [
                'content' => (string) $value,
                'hash' => hash(config('terms.hash_algorithm'), (string) $value),
            ],
        );
    }

    /**
     * The newest document of this type that has already taken effect.
     */
    public static function current(string $type = self::TYPE_TERMS_PRIVACY): ?self
    {
        if (! array_key_exists($type, static::$currentMemo)) {
            static::$currentMemo[$type] = static::query()
                ->where('type', $type)
                ->where('effective_at', '<=', now())
                ->orderByDesc('effective_at')
                ->orderByDesc('created_at')
                ->first();
        }

        return static::$currentMemo[$type];
    }

    /**
     * The version users must have accepted. Falls back to config when nothing is
     * seeded yet, so a fresh or test database behaves the same as before.
     */
    public static function currentVersion(string $type = self::TYPE_TERMS_PRIVACY): string
    {
        return static::current($type)?->version ?? config('terms.current_version');
    }

    /**
     * Clear the memo (used by tests that publish a new version mid-request).
     */
    public static function forgetCurrent(): void
    {
        static::$currentMemo = [];
    }
}

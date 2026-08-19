<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against a cached config.
     *
     * `RefreshDatabase` drops every table before the first test, so a suite
     * that boots with the wrong config does not merely fail — it silently
     * destroys the development database. A cached config does exactly that:
     * `php artisan config:cache` freezes `app.env` and the database name from
     * whatever environment cached it, and phpunit.xml's `<env>` values are
     * then never read, because the config files they feed are never
     * re-evaluated. The symptom is bewildering (403s out of
     * environment-gated middleware) and the damage is silent.
     *
     * The check reads the cache file directly rather than calling `config()`:
     * this runs before `parent::setUp()` boots the application, which is the
     * only place it can run, because `RefreshDatabase` wipes the database
     * from inside that call.
     */
    protected function setUp(): void
    {
        $cache = __DIR__.'/../bootstrap/cache/config.php';

        if (file_exists($cache)) {
            $cached = require $cache;
            $environment = $cached['app']['env'] ?? 'unknown';

            if ($environment !== 'testing') {
                $this->fail(
                    "The config is cached for the [{$environment}] environment, so this suite would "
                    .'run against that environment\'s database and RefreshDatabase would drop it. '
                    .'Run `php artisan config:clear` before the suite.',
                );
            }
        }

        parent::setUp();
    }

    /**
     * Authenticate the test client as the given user using a Supabase bearer
     * token minted with the test environment's signing secret. The user's
     * `supabase_uid` is stamped so the guard resolves them by UID.
     *
     * @return $this
     */
    protected function signInAs(User $user, ?string $uid = null): static
    {
        $uid ??= (string) Str::uuid();

        $user->forceFill(['supabase_uid' => $uid])->save();

        $token = app(SupabaseJwtService::class)->mintToken($uid, $user->email, [
            'full_name' => $user->name,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token);

        return $this;
    }
}

<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
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

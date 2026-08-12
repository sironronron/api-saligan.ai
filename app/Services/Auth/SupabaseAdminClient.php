<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server-side calls to the Supabase Auth admin API, using the project's
 * service-role key.
 *
 * Only seeding and local provisioning use this: the running app never creates
 * accounts, it only validates the tokens Supabase issues. The service-role key
 * bypasses Row Level Security, so it must never reach a browser.
 */
class SupabaseAdminClient
{
    /**
     * Whether the project credentials needed for admin calls are present.
     * Seeders check this so a machine without Supabase configured still seeds
     * its local tables instead of failing.
     */
    public function isConfigured(): bool
    {
        return filled(config('supabase.url')) && filled(config('supabase.secret_key'));
    }

    /**
     * The Supabase user id for an email address, creating the account when it
     * does not exist yet. Idempotent, so re-running a seeder adopts the
     * account created by the previous run rather than failing on a duplicate.
     *
     * @param  array<string, mixed>  $metadata  Written to `user_metadata`; the
     *                                          API reads `full_name` from it.
     */
    public function ensureUser(string $email, string $password, array $metadata = []): string
    {
        $email = strtolower(trim($email));

        $response = $this->request()->post('/auth/v1/admin/users', [
            'email' => $email,
            'password' => $password,
            // Seeded accounts skip the confirmation email; there is no inbox
            // to click through to on a development machine.
            'email_confirm' => true,
            'user_metadata' => $metadata,
        ]);

        if ($response->successful()) {
            $id = $response->json('id');

            if (is_string($id) && $id !== '') {
                return $id;
            }

            throw new RuntimeException("Supabase created {$email} but returned no id.");
        }

        // Already registered: adopt the existing account. Supabase reports this
        // as a 422 (or 400 on older versions), so the id is looked up instead
        // of treating the collision as a failure.
        $existing = $this->findIdByEmail($email);

        if ($existing !== null) {
            return $existing;
        }

        throw new RuntimeException(
            "Could not provision {$email} in Supabase (HTTP {$response->status()}): ".$response->body()
        );
    }

    /**
     * The id of an existing Supabase user, or null when the address is unknown.
     *
     * The admin list endpoint is paginated and its email filter is not
     * available on every Supabase version, so pages are scanned until the
     * address turns up or the listing runs out.
     */
    public function findIdByEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->request()->get('/auth/v1/admin/users', [
                'page' => $page,
                'per_page' => self::PER_PAGE,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $users = (array) $response->json('users', []);

            if ($users === []) {
                return null;
            }

            foreach ($users as $user) {
                if (strtolower((string) ($user['email'] ?? '')) === $email) {
                    $id = $user['id'] ?? null;

                    return is_string($id) && $id !== '' ? $id : null;
                }
            }

            if (count($users) < self::PER_PAGE) {
                return null;
            }
        }

        return null;
    }

    /**
     * Delete a Supabase account. Used when tearing down seeded data; a missing
     * account is treated as already deleted.
     */
    public function deleteUser(string $uid): void
    {
        $this->request()->delete('/auth/v1/admin/users/'.$uid);
    }

    protected function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Supabase admin calls need SUPABASE_URL and SUPABASE_SECRET_KEY.');
        }

        $key = (string) config('supabase.secret_key');

        return Http::baseUrl(rtrim((string) config('supabase.url'), '/'))
            ->withHeaders([
                'apikey' => $key,
                'Authorization' => 'Bearer '.$key,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    private const PER_PAGE = 200;

    private const MAX_PAGES = 20;
}

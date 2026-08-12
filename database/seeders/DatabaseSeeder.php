<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Auth\SupabaseAdminClient;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Throwable;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The password the seeded accounts are created with in Supabase. Sign-in
     * is handled entirely by Supabase, so this is the only credential that
     * actually works — the `users.password` column is vestigial.
     */
    protected function seedPassword(): string
    {
        return (string) env('SEED_USER_PASSWORD', 'password');
    }

    /**
     * Seed the database.
     */
    public function run(): void
    {
        $this->call([
            LegalDocumentSeeder::class,
            SystemPromptSeeder::class,
            LegalSourceSeeder::class,
            TemplateSeeder::class,
            PlansSeeder::class,
        ]);

        $test = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $this->linkToSupabase($test, $admin);
    }

    /**
     * Give each seeded account a matching Supabase user and record its uid, so
     * the seeded logins actually work: the API authenticates Supabase tokens,
     * and a local row with no `supabase_uid` can never be signed in to.
     *
     * Provisioning is skipped when the project is not configured, so a
     * checkout without Supabase credentials still seeds its local tables.
     */
    protected function linkToSupabase(User ...$users): void
    {
        $supabase = app(SupabaseAdminClient::class);

        if (! $supabase->isConfigured()) {
            $this->command?->warn(
                'Supabase is not configured (SUPABASE_URL / SUPABASE_SECRET_KEY), so the seeded '
                .'users have no Supabase account and cannot sign in. Local records were still created.'
            );

            return;
        }

        foreach ($users as $user) {
            try {
                $uid = $supabase->ensureUser($user->email, $this->seedPassword(), [
                    'full_name' => $user->name,
                ]);

                $user->forceFill(['supabase_uid' => $uid])->save();

                $this->command?->info("Linked {$user->email} to Supabase user {$uid}.");
            } catch (Throwable $exception) {
                // One unreachable account must not abort the rest of the seed:
                // the local rows are already committed and the others may well
                // provision fine.
                $this->command?->error("Could not provision {$user->email} in Supabase: {$exception->getMessage()}");
            }
        }
    }
}

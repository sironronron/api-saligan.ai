<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);
    }
}

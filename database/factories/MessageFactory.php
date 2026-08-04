<?php

namespace Database\Factories;

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::User,
            'content' => fake()->sentence(),
            'provider' => ChatProvider::Ollama,
            'cited_chunk_ids' => [],
            'cited_legal_chunk_ids' => [],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\LetterComment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LetterComment>
 */
class LetterCommentFactory extends Factory
{
    protected $model = LetterComment::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'block_id' => fn () => 'block-'.fake()->uuid(),
            'parent_id' => null,
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * Mark the comment as a reply to an existing root comment.
     */
    public function replyTo(LetterComment $parent): static
    {
        return $this->state(fn (): array => [
            'message_id' => $parent->message_id,
            'block_id' => $parent->block_id,
            'parent_id' => $parent->id,
        ]);
    }
}

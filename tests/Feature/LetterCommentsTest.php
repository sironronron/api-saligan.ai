<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\LetterComment;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VettingRequest;

/**
 * Build a user with an active subscription, since the API gates authenticated
 * access behind one. Mirrors the setup in GeneratedDocumentsTest.
 */
function userWithSubscription(): User
{
    $user = User::factory()->create();

    $plan = Plan::where('slug', 'pro')->first()
        ?? Plan::factory()->pro()->create();

    Subscription::factory()->for($user)->create([
        'plan_id' => $plan->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->user = userWithSubscription();

    $this->conversation = Conversation::factory()->for($this->user)->create();

    $this->draft = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Here is your letter.',
        'metadata' => [
            'letter_draft' => ['content' => ['type' => 'doc', 'content' => []], 'title' => 'My Letter'],
        ],
    ]);
});

it('requires authentication', function () {
    $this->getJson("/api/messages/{$this->draft->id}/comments")->assertStatus(401);
});

it('lets the draft owner list and add comments', function () {
    LetterComment::factory()->for($this->draft, 'message')->create([
        'block_id' => 'block-1',
        'user_id' => $this->user->id,
        'body' => 'Check this clause.',
    ]);

    $this->signInAs($this->user)
        ->getJson("/api/messages/{$this->draft->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.block_id', 'block-1');

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-2',
            'body' => 'Add a salutation here.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.block_id', 'block-2')
        ->assertJsonPath('data.user.id', $this->user->id);
});

it('threads replies beneath a block root comment', function () {
    $root = LetterComment::factory()->for($this->draft, 'message')->create([
        'block_id' => 'block-1',
        'user_id' => $this->user->id,
        'body' => 'Root note.',
    ]);

    $reply = $this->signInAs($this->user)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-1',
            'parent_id' => $root->id,
            'body' => 'Agreed, but tighten the wording.',
        ])
        ->assertCreated()
        ->json('data');

    expect($reply['parent_id'])->toBe($root->id);

    $this->signInAs($this->user)
        ->getJson("/api/messages/{$this->draft->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.replies.0.id', $reply['id'])
        ->assertJsonPath('data.0.replies.0.body', 'Agreed, but tighten the wording.');
});

it('rejects a reply to a comment on a different block', function () {
    $root = LetterComment::factory()->for($this->draft, 'message')->create([
        'block_id' => 'block-1',
        'user_id' => $this->user->id,
        'body' => 'Root note.',
    ]);

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-2',
            'parent_id' => $root->id,
            'body' => 'Reply on the wrong block.',
        ])
        ->assertStatus(422);
});

it('forbids a stranger from commenting on a draft they cannot open', function () {
    $stranger = userWithSubscription();

    $this->signInAs($stranger)
        ->getJson("/api/messages/{$this->draft->id}/comments")
        ->assertStatus(403);

    $this->signInAs($stranger)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-1',
            'body' => 'Nice try.',
        ])
        ->assertStatus(403);
});

it('lets a case assignee comment on the draft', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $this->conversation->update(['case_id' => $case->id]);

    $member = userWithSubscription();
    $case->assignees()->attach($member->id);

    $this->signInAs($member)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-1',
            'body' => 'From the assignee.',
        ])
        ->assertCreated();
});

it('lets the assigned lawyer of a vetting request comment on the linked draft', function () {
    $lawyer = userWithSubscription();
    $document = Document::factory()->for($this->user)->create();

    VettingRequest::factory()->create([
        'submitter_id' => $this->user->id,
        'assigned_lawyer_id' => $lawyer->id,
        'document_id' => $document->id,
        'letter_draft_message_id' => $this->draft->id,
    ]);

    $this->signInAs($lawyer)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-1',
            'body' => 'As your vetting lawyer, revise this paragraph.',
        ])
        ->assertCreated();

    // A lawyer on an unrelated request still cannot open it.
    $otherLawyer = userWithSubscription();
    VettingRequest::factory()->create([
        'submitter_id' => $this->user->id,
        'assigned_lawyer_id' => $otherLawyer->id,
        'document_id' => $document->id,
    ]);

    $this->signInAs($otherLawyer)
        ->postJson("/api/messages/{$this->draft->id}/comments", [
            'block_id' => 'block-1',
            'body' => 'Sneaking in.',
        ])
        ->assertStatus(403);
});

it('only lets the author delete their own comment', function () {
    $comment = LetterComment::factory()->for($this->draft, 'message')->create([
        'block_id' => 'block-1',
        'user_id' => $this->user->id,
        'body' => 'Mine.',
    ]);

    $other = userWithSubscription();
    $case = LegalCase::factory()->for($this->user)->create();
    $this->conversation->update(['case_id' => $case->id]);
    $case->assignees()->attach($other->id);

    // A case member can read but not delete someone else's comment.
    $this->signInAs($other)
        ->deleteJson("/api/messages/{$this->draft->id}/comments/{$comment->id}")
        ->assertStatus(403);

    $this->signInAs($this->user)
        ->deleteJson("/api/messages/{$this->draft->id}/comments/{$comment->id}")
        ->assertOk();

    expect(LetterComment::find($comment->id))->toBeNull();
});

it('refuses to comment on a message that is not a letter draft', function () {
    $plain = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'No draft here.',
    ]);

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$plain->id}/comments", [
            'block_id' => 'block-1',
            'body' => 'Hello?',
        ])
        ->assertStatus(404);
});

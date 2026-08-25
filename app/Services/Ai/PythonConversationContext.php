<?php

namespace App\Services\Ai;

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Template;
use App\Services\Chat\ChatService;
use App\Services\MatterMemory\MatterMemoryService;
use App\Support\CaseContextBlock;
use App\Support\PlanFeatures;
use App\Support\UserProfile;

class PythonConversationContext
{
    public function __construct(
        private readonly CaseContextBlock $caseContext,
        private readonly MatterMemoryService $memory,
        private readonly ChatService $chat,
    ) {}

    /** @return array<string, mixed> */
    public function for(Conversation $conversation): array
    {
        $conversation->loadMissing(['user.organization.subscription.plan', 'user.subscriptions.plan', 'case.defaultTemplate']);

        $user = $conversation->user;
        $case = $conversation->case;
        $template = $case?->defaultTemplate;
        [$provider, $model] = $this->providerAndModel($conversation);

        return [
            'user_id' => $user->id,
            'case_id' => $case?->id,
            'deep_research' => PlanFeatures::has($user, PlanFeatures::DEEP_RESEARCH),
            'provider' => $provider,
            'model' => $model,
            'plan_tier' => $user->plan()?->slug,
            'user_profile' => UserProfile::blockFor($user) ?? '',
            'case_context' => $case !== null ? $this->caseContext->for($case) : '',
            'matter_memory' => $case !== null ? $this->memory->getMemoryBlock($case) : '',
            'template' => $template !== null ? $this->template($template) : '',
            'recent_intake_values' => (object) $this->chat->recentIntakeValues($conversation),
            'messages' => $conversation->messages()
                ->whereIn('role', [MessageRole::User->value, MessageRole::Assistant->value])
                ->latest()
                ->limit(20)
                ->get()
                ->reverse()
                ->map(fn ($message): array => [
                    'role' => $message->role->value,
                    'content' => $message->content,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{0: string, 1: string} */
    protected function providerAndModel(Conversation $conversation): array
    {
        $provider = ChatProvider::fromConfig();

        return match ($provider) {
            ChatProvider::Anthropic => filled(config('ai.providers.anthropic.key')) ? [
                'anthropic',
                PlanFeatures::has($conversation->user, PlanFeatures::FRONTIER_MODEL)
                    ? (string) config('saligan.chat.anthropic_model')
                    : (string) config('saligan.chat.anthropic_base_model'),
            ] : $this->ollama(),
            ChatProvider::Gemini => filled(config('ai.providers.gemini.key'))
                ? ['gemini', (string) config('saligan.chat.gemini_model')]
                : $this->ollama(),
            default => $this->ollama(),
        };
    }

    /** @return array{0: string, 1: string} */
    protected function ollama(): array
    {
        return ['ollama', (string) config('saligan.chat.ollama_model')];
    }

    protected function template(Template $template): string
    {
        return collect([
            "Template: {$template->name}",
            "Category: {$template->category}",
            filled($template->legal_subtype) ? "Legal sub-type: {$template->legal_subtype}" : null,
            filled($template->content) ? "Content:\n{$template->content}" : null,
            filled($template->placeholder_fields)
                ? 'Placeholder fields: '.json_encode($template->placeholder_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ])->filter()->implode("\n");
    }
}

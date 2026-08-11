<?php

namespace App\Services\MatterMemory;

use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\User;
use App\Support\PromptGuard;
use Illuminate\Support\Collection;

class MatterMemoryService
{
    /**
     * Retrieve active memories for a case, scoped to the organization.
     *
     * @return Collection<int, MatterMemory>
     */
    public function getMemories(LegalCase $case, ?string $type = null): Collection
    {
        $query = MatterMemory::query()
            ->forOrganization($case->organization_id)
            ->forCase($case->id)
            ->active()
            ->orderBy('created_at', 'desc');

        if ($type !== null) {
            $query->ofType($type);
        }

        return $query->get();
    }

    /**
     * Retrieve memories formatted for inclusion in the system prompt.
     */
    public function getMemoryBlock(LegalCase $case): string
    {
        $memories = $this->getMemories($case);

        if ($memories->isEmpty()) {
            return 'No matter-specific memory entries recorded for this matter.';
        }

        $lines = [];
        foreach ($memories as $memory) {
            $lines[] = "- [{$memory->type}] ".PromptGuard::wrap($memory->content);
        }

        return implode("\n", $lines);
    }

    /**
     * Store a new memory entry for a matter.
     */
    public function store(
        LegalCase $case,
        User $user,
        string $type,
        string $content,
        ?array $metadata = null,
    ): MatterMemory {
        return MatterMemory::create([
            'organization_id' => $case->organization_id,
            'case_id' => $case->id,
            'user_id' => $user->id,
            'type' => $type,
            'content' => $content,
            'metadata' => $metadata,
            'is_active' => true,
        ]);
    }

    /**
     * Check if a similar memory already exists to avoid duplicates.
     */
    public function existsSimilar(LegalCase $case, string $type, string $content): bool
    {
        return MatterMemory::query()
            ->forOrganization($case->organization_id)
            ->forCase($case->id)
            ->active()
            ->ofType($type)
            ->where('content', $content)
            ->exists();
    }

    /**
     * Soft-deactivate a memory entry (preserves audit trail).
     */
    public function deactivate(string $memoryId): bool
    {
        return (bool) MatterMemory::where('id', $memoryId)->update(['is_active' => false]);
    }

    /**
     * Check if the case allows new memory writes based on retention status.
     */
    public function canWrite(LegalCase $case): bool
    {
        return ! in_array($case->retention_status, ['closed-pending-deletion', 'on-legal-hold'], true);
    }

    /**
     * Check if the case is on legal hold.
     */
    public function isOnLegalHold(LegalCase $case): bool
    {
        return $case->retention_status === 'on-legal-hold';
    }
}

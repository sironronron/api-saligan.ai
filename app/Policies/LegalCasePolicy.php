<?php

namespace App\Policies;

use App\Models\LegalCase;
use App\Models\User;

/**
 * Who may do what with a case.
 *
 * The rule the whole file turns on: working a case and disposing of it are
 * different rights. An assignee is there to do the work — read the matter,
 * message it, attach documents, move its status — so `view` and `update` are
 * open to them. Archiving, deleting, and changing who else is on the case stay
 * with the owner, because those are decisions about the matter itself rather
 * than work inside it.
 */
class LegalCasePolicy
{
    /**
     * Open the case and read everything in it.
     */
    public function view(User $user, LegalCase $case): bool
    {
        return $case->isAccessibleBy($user);
    }

    /**
     * Work the case: message it, attach documents, edit its details, move its
     * status. Anyone on the case can do this.
     */
    public function update(User $user, LegalCase $case): bool
    {
        return $case->isAccessibleBy($user);
    }

    /**
     * Archive, restore, or permanently delete. Owner only: an assignee taking
     * the matter away from everyone else is not a shared-work decision.
     */
    public function delete(User $user, LegalCase $case): bool
    {
        return $case->user_id === $user->id;
    }

    /**
     * Change who is on the case. The owner always may; an organization admin
     * may too, so a firm is not locked out of reassigning a colleague's matter
     * when that colleague is away.
     */
    public function manageAssignees(User $user, LegalCase $case): bool
    {
        // A finished matter's roster is a record, not a working list. Reopening
        // the case is what restores the right — nobody gets to edit around it.
        if ($case->isReadOnly()) {
            return false;
        }

        if ($case->user_id === $user->id) {
            return true;
        }

        // Both sides must be in the same organization, and it must be a real
        // one: two solo accounts both carrying a null organization_id are not
        // colleagues, and must never compare equal here.
        if ($case->organization_id === null || $user->organization_id !== $case->organization_id) {
            return false;
        }

        return $user->canManageOrganization();
    }
}

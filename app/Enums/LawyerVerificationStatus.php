<?php

namespace App\Enums;

enum LawyerVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    /**
     * Whether a lawyer in this state may receive vetting requests.
     */
    public function isActive(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether this is a terminal rejection an admin decided on.
     */
    public function isRejected(): bool
    {
        return in_array($this, [self::Rejected, self::Revoked], true);
    }
}

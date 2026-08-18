<?php

namespace App\Enums;

enum VettingRequestStatus: string
{
    case PaymentPending = 'payment_pending';
    case Pending = 'pending';
    case Matched = 'matched';
    case Waiting = 'waiting';
    case Accepted = 'accepted';
    case UnderReview = 'under_review';
    case Vetted = 'vetted';
    case Notarized = 'notarized';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case FailedVerification = 'failed_verification';

    /**
     * The states in which a request is still open and can be worked.
     */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::PaymentPending,
            self::Pending,
            self::Matched,
            self::Waiting,
            self::Accepted,
            self::UnderReview,
            self::Vetted,
            self::Notarized,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PaymentPending => 'Awaiting payment',
            self::Pending => 'Pending',
            self::Matched => 'Matching a lawyer',
            self::Waiting => 'Waiting for a lawyer',
            self::Accepted => 'Accepted',
            self::UnderReview => 'Under review',
            self::Vetted => 'Vetted',
            self::Notarized => 'Notarized',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Declined => 'Declined',
            self::FailedVerification => 'Identity check failed',
        };
    }
}

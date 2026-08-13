<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A trial code could not be redeemed. Every message on this exception is
 * written for the person typing the code and is safe to return verbatim.
 */
class TrialRedemptionException extends RuntimeException
{
    //
}

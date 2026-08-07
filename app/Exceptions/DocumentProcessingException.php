<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A document-processing failure whose message is safe to show to the user
 * (e.g. "no text could be read from the image"). Any other exception raised
 * while ingesting a document is treated as internal and its raw message is
 * never exposed.
 */
class DocumentProcessingException extends RuntimeException
{
    //
}

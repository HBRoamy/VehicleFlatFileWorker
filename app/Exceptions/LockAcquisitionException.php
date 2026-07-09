<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a file lock cannot be acquired because another instance already
 * holds it. This is an expected, recoverable condition: the poller simply
 * skips the file and acknowledges the message.
 */
final class LockAcquisitionException extends RuntimeException
{
}

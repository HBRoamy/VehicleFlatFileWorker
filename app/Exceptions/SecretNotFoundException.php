<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested secret key is not present in the secrets backend.
 */
final class SecretNotFoundException extends RuntimeException
{
}

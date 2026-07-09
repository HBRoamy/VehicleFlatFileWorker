<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested object cannot be found in the file store.
 */
final class FileNotFoundException extends RuntimeException
{
}

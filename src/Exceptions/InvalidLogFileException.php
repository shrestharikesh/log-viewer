<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Exceptions;

use RuntimeException;

/**
 * Thrown when a client-supplied file identifier cannot be resolved to a real,
 * allowed file inside a configured source root (unknown file, disallowed
 * extension, hidden pattern, or attempted path traversal).
 */
class InvalidLogFileException extends RuntimeException
{
    public static function notFound(string $identifier): self
    {
        return new self("Log file [{$identifier}] was not found or is not accessible.");
    }

    public static function outsideRoot(string $identifier): self
    {
        return new self("Log file [{$identifier}] resolves outside of an allowed source root.");
    }

    public static function disallowed(string $identifier): self
    {
        return new self("Log file [{$identifier}] is not an allowed log file.");
    }
}

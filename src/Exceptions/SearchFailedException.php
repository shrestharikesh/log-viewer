<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Exceptions;

use RuntimeException;

/**
 * A search engine (rg/grep) exited with an error, e.g. an invalid regex or a
 * binary that could not run. Raised instead of reporting "no matches", which
 * would be indistinguishable from a real empty result.
 */
final class SearchFailedException extends RuntimeException
{
    public static function engine(string $engine, ?int $exitCode, string $stderr): self
    {
        $detail = trim($stderr) === '' ? 'no error output' : trim(substr($stderr, 0, 500));

        return new self("Search engine [{$engine}] failed (exit code {$exitCode}): {$detail}");
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Support;

use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Support\LogFile;

/**
 * A LogSourceInterface decorator whose reader() sleeps from its Nth call on.
 *
 * The CLI engines open a reader lazily inside the stdin generator they hand
 * to Symfony Process, and Process::start() pulls the first chunk of that
 * generator before its own timeout check. Stalling reader() therefore makes
 * launching a piece's process take as long as the test wants, with no
 * dependence on how fast the host forks.
 */
final class StallingLogSource implements LogSourceInterface
{
    /** reader() calls so far. */
    public int $reads = 0;

    /** reader() calls that slept. */
    public int $stalls = 0;

    public function __construct(
        private readonly LogSourceInterface $inner,
        private readonly float $stallSeconds,
        // 1-based reader() call from which every call sleeps; null never.
        private readonly ?int $stallFromRead = null,
    ) {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function files(): array
    {
        return $this->inner->files();
    }

    public function resolve(string $identifier): LogFile
    {
        return $this->inner->resolve($identifier);
    }

    public function exists(LogFile $file): bool
    {
        return $this->inner->exists($file);
    }

    public function size(LogFile $file): int
    {
        return $this->inner->size($file);
    }

    public function lastModified(LogFile $file): int
    {
        return $this->inner->lastModified($file);
    }

    public function reader(LogFile $file)
    {
        $this->reads++;

        if ($this->stallFromRead !== null && $this->reads >= $this->stallFromRead) {
            $this->stalls++;
            usleep((int) ($this->stallSeconds * 1_000_000));
        }

        return $this->inner->reader($file);
    }

    public function localPath(LogFile $file): ?string
    {
        return $this->inner->localPath($file);
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

/**
 * How a RangeScanner::scanRange() call ended.
 *
 * `resumeOffset` is where a forward scan would continue: the range end when
 * the scan completed, otherwise just past the last record handed to the
 * callback (or the range start if none was).
 */
final class ScanOutcome
{
    public function __construct(
        public readonly int $resumeOffset,
        public readonly bool $completed,
        public readonly bool $timedOut = false,
    ) {
    }

    public static function completed(int $end): self
    {
        return new self($end, true);
    }
}

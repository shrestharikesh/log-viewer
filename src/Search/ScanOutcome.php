<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

/**
 * How a RangeScanner::scanRange() call ended.
 *
 * `resumeOffset` is where a forward scan would continue: the range end when
 * the scan completed, otherwise the furthest of: just past the last record
 * handed to the callback, the end of the part of the range already fully
 * scanned, or the range start if neither applies.
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

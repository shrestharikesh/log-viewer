<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Reading\TimeOffsetLocator;

/**
 * Tuning shared by the built-in search engines.
 *
 * Without a locator, date filters are applied after matching only (no seek).
 * SearchManager builds this from the `log-explorer.search` config.
 */
final class ScanOptions
{
    public function __construct(
        // Binary-searches dateFrom/dateTo into a byte range; null disables it.
        public readonly ?TimeOffsetLocator $locator = null,
        // Seconds the seek widens the range by on each side, to absorb clock
        // skew between workers writing the same file.
        public readonly int $timeSeekMargin = 300,
        // Bytes per window for newest-first (backward) search.
        public readonly int $backwardWindow = 64 * 1024 * 1024,
        // rg/grep range scans run in pieces, one process each: 1 MB first,
        // doubling up to 1 GB, so a timeout loses at most one piece and every
        // page makes progress. A value below 1 MB also caps the first piece;
        // a value above 1 GB raises the cap.
        public readonly int $forwardPiece = 64 * 1024 * 1024,
    ) {
    }
}

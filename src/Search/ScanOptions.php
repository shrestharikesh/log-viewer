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
    ) {
    }
}

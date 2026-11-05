<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;

/**
 * A search engine that can scan an arbitrary byte range of one file.
 *
 * This is the primitive that SearchRunner builds forward, newest-first and
 * count searches on. It is separate from Searcher so the public Searcher
 * contract stays unchanged for anyone implementing it.
 */
interface RangeScanner extends Searcher
{
    /**
     * Visit, oldest first, every record whose header starts in [$start, $end)
     * and that matches the criteria's query and structured filters.
     *
     * $onMatch receives each matching record and returns false to stop the
     * scan early. With $headersOnly the engine may hand over just the parsed
     * header line instead of the folded record, which is all a count needs.
     *
     * Criteria limit, fromOffset, endOffset and direction are NOT applied
     * here; the caller has already turned them into $start/$end.
     *
     * @param  callable(LogLine):bool  $onMatch
     */
    public function scanRange(
        LogFile $file,
        SearchCriteria $criteria,
        int $start,
        int $end,
        float $deadline,
        callable $onMatch,
        bool $headersOnly = false,
    ): ScanOutcome;
}

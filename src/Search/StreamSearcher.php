<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;

/**
 * Pure-PHP streaming search. Always available (no external binary) and works
 * against any LogSource, including remote ones with no local path.
 *
 * Reads one whole RECORD at a time (a header plus any continuation lines —
 * see RecordAssembler) from a byte offset, applies the filters, and stops at
 * the end of its byte range, the result limit or a wall-clock budget. It
 * never holds more than one record plus the (bounded) match buffer in memory.
 *
 * Range selection (time seek, cursor, newest-first windows) and the shared
 * limit/timeout budget live in SearchRunner; this class only scans a range.
 */
final class StreamSearcher implements RangeScanner
{
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly RecordAssembler $records,
        private readonly ScanOptions $options = new ScanOptions(),
    ) {
    }

    public function name(): string
    {
        return 'stream';
    }

    public function available(LogFile $file): bool
    {
        return true;
    }

    public function search(LogFile $file, SearchCriteria $criteria): SearchResult
    {
        return $this->searchMany([$file], $criteria)[0]->result;
    }

    /**
     * There is no multi-file streaming primitive in PHP, so the batch is a
     * loop, over ONE shared budget (see SearchRunner).
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function searchMany(array $files, SearchCriteria $criteria): array
    {
        if ($files === []) {
            return [];
        }

        return (new SearchRunner($this, $this->source, $this->records, $this->options))
            ->search(array_values($files), $criteria);
    }

    public function scanRange(
        LogFile $file,
        SearchCriteria $criteria,
        int $start,
        int $end,
        float $deadline,
        callable $onMatch,
        bool $headersOnly = false,
    ): ScanOutcome {
        $size = $this->source->size($file);
        $end = min($end, $size);

        if ($start >= $end) {
            return ScanOutcome::completed(max($start, $end));
        }

        $stream = $this->source->reader($file);
        $matcher = $this->compileMatcher($criteria);
        $offset = $start;
        $scanned = 0;

        try {
            // A start inside a record (a hand-made cursor) must not turn the
            // rest of that record into a header-less orphan match: skip to
            // the record's end, the same "header in [start, end)" rule the
            // CLI engines apply.
            $head = $this->records->recordStartContaining($stream, $start, $size);
            if ($head < $start) {
                $offset = max($start, $this->records->foldFrom($stream, $head, $size)?->endOffset ?? $start);
            }

            while ($offset < $end) {
                // Check the clock every 256 records, so a sparse match over a
                // huge range still returns control to the user.
                if (($scanned++ & 0xFF) === 0 && microtime(true) >= $deadline) {
                    return new ScanOutcome($offset, false, true);
                }

                // One whole record (header + any folded continuation lines),
                // not one physical line, so the query can hit anywhere in it.
                // Bounded by $end, not $size: a fold never runs into the next
                // window/page (see CommandLineSearcher::scanRange).
                $record = $this->records->foldFrom($stream, $offset, $end);
                if ($record === null) {
                    break;
                }

                $offset = $record->endOffset;

                if ($matcher($record->raw) && $criteria->passesStructuredFilters($record)) {
                    if ($onMatch($record) === false) {
                        return new ScanOutcome($offset, false);
                    }
                }
            }
        } finally {
            fclose($stream);
        }

        return ScanOutcome::completed($end);
    }

    /**
     * @return callable(string):bool
     */
    private function compileMatcher(SearchCriteria $criteria): callable
    {
        if (! $criteria->hasTextQuery()) {
            return static fn (string $line): bool => true;
        }

        if ($criteria->regex) {
            $delim = '~';
            $pattern = $delim.str_replace($delim, '\\'.$delim, $criteria->query).$delim
                .($criteria->caseSensitive ? '' : 'i');

            // Validate once; an invalid pattern degrades to a literal search.
            if (@preg_match($pattern, '') === false) {
                return $this->literalMatcher($criteria);
            }

            return static fn (string $line): bool => preg_match($pattern, $line) === 1;
        }

        return $this->literalMatcher($criteria);
    }

    private function literalMatcher(SearchCriteria $criteria): callable
    {
        $needle = $criteria->query;

        return $criteria->caseSensitive
            ? static fn (string $line): bool => str_contains($line, $needle)
            : static fn (string $line): bool => stripos($line, $needle) !== false;
    }
}

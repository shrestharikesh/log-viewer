<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;

/**
 * Pure-PHP streaming search. Always available (no external binary) and works
 * against any LogSource, including remote ones with no local path.
 *
 * Reads one whole RECORD at a time (a header plus any continuation lines —
 * see RecordAssembler) from a byte offset, applies the filters, and stops at
 * the result limit or a wall-clock budget — returning a cursor to resume
 * from. It never holds more than one record plus the (bounded) match buffer
 * in memory.
 */
final class StreamSearcher implements Searcher
{
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly RecordAssembler $records,
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
        $scan = $this->scan($file, $criteria, $criteria->limit, microtime(true) + $criteria->timeout);

        $complete = ! $scan['timedOut'] && $scan['offset'] >= $scan['size'] && count($scan['matches']) < $criteria->limit;

        return new SearchResult(
            matches: $scan['matches'],
            nextCursor: $complete ? null : (new ByteCursor($scan['offset'], $scan['fingerprint']))->encode(),
            complete: $complete,
            engine: 'stream',
            truncated: count($scan['matches']) >= $criteria->limit,
        );
    }

    /**
     * There is no multi-file streaming primitive in PHP, so the batch is simply
     * a loop — but over ONE shared budget: each file is capped at whatever is
     * left of the limit, and the wall-clock deadline is computed once for the
     * whole batch. Files after the budget runs out are not opened at all.
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function searchMany(array $files, SearchCriteria $criteria): array
    {
        if ($files === []) {
            return [];
        }

        $deadline = microtime(true) + $criteria->timeout;
        $remaining = $criteria->limit;
        $stopped = false;

        $results = [];

        foreach (array_values($files) as $file) {
            if ($stopped || $remaining <= 0 || microtime(true) > $deadline) {
                $stopped = true;

                // Never scanned: report it as resumable from the requested
                // offset rather than pretending it had no matches.
                $results[] = new FileSearchResult(
                    file: $file,
                    result: new SearchResult(
                        matches: [],
                        nextCursor: (new ByteCursor(
                            $criteria->fromOffset,
                            ByteCursor::fingerprint($this->source->size($file), $this->source->lastModified($file)),
                        ))->encode(),
                        complete: false,
                        engine: 'stream',
                        truncated: $remaining <= 0,
                    ),
                );

                continue;
            }

            $budget = $remaining;
            $scan = $this->scan($file, $criteria, $budget, $deadline);

            $matched = count($scan['matches']);
            $remaining -= $matched;

            // This file consumed the entire remaining budget, so there may well
            // be more matches in it (and in every file after it).
            $hitLimit = $matched >= $budget;

            if ($scan['timedOut'] || $hitLimit) {
                $stopped = true;
            }

            $complete = ! $scan['timedOut'] && ! $hitLimit && $scan['offset'] >= $scan['size'];

            $results[] = new FileSearchResult(
                file: $file,
                result: new SearchResult(
                    matches: $scan['matches'],
                    nextCursor: $complete ? null : (new ByteCursor($scan['offset'], $scan['fingerprint']))->encode(),
                    complete: $complete,
                    engine: 'stream',
                    truncated: $hitLimit,
                ),
            );
        }

        return $results;
    }

    /**
     * Scan one file for up to $limit matches, giving up at $deadline.
     *
     * Shared by the single-file and batch paths so the matching, filtering and
     * memory characteristics are identical either way.
     *
     * @return array{matches:LogLine[], offset:int, size:int, timedOut:bool, fingerprint:string}
     */
    private function scan(LogFile $file, SearchCriteria $criteria, int $limit, float $deadline): array
    {
        $size = $this->source->size($file);
        $mtime = $this->source->lastModified($file);
        $fingerprint = ByteCursor::fingerprint($size, $mtime);

        $stream = $this->source->reader($file);
        $matcher = $this->compileMatcher($criteria);

        $offset = max(0, min($criteria->fromOffset, $size));

        $matches = [];
        $timedOut = false;

        try {
            while ($offset < $size && count($matches) < $limit) {
                // One whole record (header + any folded continuation lines),
                // not one physical line — see RecordAssembler. The query is
                // matched against the full folded text, so it can hit
                // anywhere in the record, not just its header line.
                $record = $this->records->foldFrom($stream, $offset, $size);
                if ($record === null) {
                    break;
                }

                if ($matcher($record->raw) && $this->passesStructuredFilters($record, $criteria)) {
                    $matches[] = $record;
                }

                $offset = $record->endOffset;

                // Re-check the clock periodically so a sparse match over a huge
                // file still returns control to the user.
                if ((count($matches) & 0x3F) === 0 && microtime(true) > $deadline) {
                    $timedOut = true;
                    break;
                }
            }
        } finally {
            fclose($stream);
        }

        return [
            'matches' => $matches,
            'offset' => $offset,
            'size' => $size,
            'timedOut' => $timedOut,
            'fingerprint' => $fingerprint,
        ];
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

    private function passesStructuredFilters(LogLine $line, SearchCriteria $criteria): bool
    {
        $parsed = $line->parsed;

        if ($criteria->level !== null && $criteria->level !== '') {
            if ($parsed?->level === null || strcasecmp($parsed->level, $criteria->level) !== 0) {
                return false;
            }
        }

        if (($criteria->dateFrom !== null || $criteria->dateTo !== null) && $parsed?->datetime !== null) {
            $ts = strtotime($parsed->datetime);
            if ($ts !== false) {
                if ($criteria->dateFrom !== null && $ts < (int) strtotime($criteria->dateFrom)) {
                    return false;
                }
                if ($criteria->dateTo !== null && $ts > (int) strtotime($criteria->dateTo)) {
                    return false;
                }
            }
        }

        return true;
    }
}

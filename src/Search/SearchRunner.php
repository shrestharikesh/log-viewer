<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use InvalidArgumentException;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;
use Vendor\LogExplorer\Support\Timestamp;

/**
 * Engine-agnostic search orchestration over RangeScanner::scanRange().
 *
 * Every mode follows the same steps:
 *   1. turn dateFrom/dateTo into a byte range with the time seek
 *      (range()), then narrow it by the cursor and endOffset;
 *   2. scan only that range;
 *   3. spend ONE limit and ONE wall-clock deadline across all the files,
 *      in turn. Files after the budget runs out are not opened and come
 *      back resumable.
 *
 * Forward pages are O(page), not O(offset), because the cursor becomes the
 * range start. Backward (newest-first) scans fixed-size windows from the end
 * of the range, so the newest page costs one window, not the whole file.
 *
 * @internal built by the engines and SearchManager; not a public extension point
 */
final class SearchRunner
{
    /**
     * First backward window of a request, in bytes. Windows then double up
     * to `backwardWindow`, so a slow scan (stream engine, idle-priority IO)
     * still completes at least one window per request and the cursor always
     * moves, instead of retrying one too-large window forever.
     */
    private const FIRST_BACKWARD_WINDOW = 1024 * 1024;

    private const BUCKET_FORMATS = [
        'minute' => 'Y-m-d H:i',
        'hour' => 'Y-m-d H:00',
        'day' => 'Y-m-d',
    ];

    public function __construct(
        private readonly RangeScanner $engine,
        private readonly LogSourceInterface $source,
        private readonly RecordAssembler $records,
        private readonly ScanOptions $options = new ScanOptions(),
    ) {
    }

    /**
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function search(array $files, SearchCriteria $criteria): array
    {
        return $criteria->isBackward()
            ? $this->backward($files, $criteria)
            : $this->forward($files, $criteria);
    }

    /**
     * Oldest first. The cursor is the offset just past the last match.
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function forward(array $files, SearchCriteria $criteria): array
    {
        $deadline = microtime(true) + $criteria->timeout;
        $remaining = $criteria->limit;
        $stopped = false;
        $results = [];

        foreach (array_values($files) as $file) {
            $size = $this->source->size($file);
            $fingerprint = ByteCursor::fingerprint($size, $this->source->lastModified($file));

            if ($stopped || $remaining <= 0 || microtime(true) >= $deadline) {
                $stopped = true;
                $results[] = $this->unscanned($file, $criteria, $fingerprint, $remaining <= 0);

                continue;
            }

            [$start, $end] = $this->range($file, $criteria, $size);
            $start = min($end, max($start, $criteria->fromOffset));

            $budget = $remaining;
            $matches = [];
            $outcome = $this->engine->scanRange(
                $file,
                $criteria,
                $start,
                $end,
                $deadline,
                static function (LogLine $record) use (&$matches, $budget): bool {
                    $matches[] = $record;

                    return count($matches) < $budget;
                },
            );

            $hitLimit = count($matches) >= $budget;
            $remaining -= count($matches);
            $complete = $outcome->completed && ! $hitLimit;
            $stopped = ! $complete;

            $results[] = new FileSearchResult($file, new SearchResult(
                matches: $matches,
                nextCursor: $complete ? null : (new ByteCursor($outcome->resumeOffset, $fingerprint))->encode(),
                complete: $complete,
                engine: $this->engine->name(),
                truncated: $hitLimit,
                range: ['start' => $start, 'end' => $end],
                direction: SearchCriteria::FORWARD,
            ));
        }

        return $results;
    }

    /**
     * Newest first. Scans windows from the end of the range toward its start
     * (1 MB first, doubling up to `backwardWindow` bytes), each aligned to a
     * record header, and
     * returns each window's matches newest first. The cursor is an EXCLUSIVE
     * upper bound: the header offset of the oldest record already returned,
     * or the window boundary.
     *
     * Each window is scanned in full (that is what makes it newest-first),
     * so the cost of a page is bounded by the window size, not the file
     * size. Only the newest `limit` matches of a window are kept, in a ring
     * buffer, so memory stays bounded however many lines match.
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function backward(array $files, SearchCriteria $criteria): array
    {
        $deadline = microtime(true) + $criteria->timeout;
        $remaining = $criteria->limit;
        $stopped = false;
        $results = [];

        foreach (array_values($files) as $file) {
            $size = $this->source->size($file);
            $fingerprint = ByteCursor::fingerprint($size, $this->source->lastModified($file));

            if ($stopped || $remaining <= 0 || microtime(true) >= $deadline) {
                $stopped = true;
                $results[] = $this->unscanned($file, $criteria, $fingerprint, $remaining <= 0, SearchCriteria::BACKWARD);

                continue;
            }

            [$start, $end] = $this->range($file, $criteria, $size);
            $hi = $criteria->fromOffset > 0 ? max($start, min($criteria->fromOffset, $end)) : $end;

            $matches = [];
            $timedOut = false;
            $hitLimit = false;
            $stream = null;
            $maxWindow = max(1, $this->options->backwardWindow);
            $window = min(self::FIRST_BACKWARD_WINDOW, $maxWindow);

            try {
                while ($hi > $start) {
                    if (microtime(true) >= $deadline) {
                        $timedOut = true;
                        break;
                    }

                    $stream ??= $this->source->reader($file);
                    $lo = $this->windowStart($stream, $size, $start, $hi, $window);
                    $window = min($window * 2, $maxWindow);
                    $budget = $remaining - count($matches);

                    // Ring buffer of the newest $budget matches in this window.
                    $ring = [];
                    $seen = 0;
                    $outcome = $this->engine->scanRange(
                        $file,
                        $criteria,
                        $lo,
                        $hi,
                        $deadline,
                        static function (LogLine $record) use (&$ring, &$seen, $budget): bool {
                            $ring[$seen % $budget] = $record;
                            $seen++;

                            return true;
                        },
                    );

                    if (! $outcome->completed) {
                        // A half-scanned window can't be returned newest
                        // first without a gap; resume this window next time.
                        $timedOut = true;
                        break;
                    }

                    for ($i = $seen - 1; $i >= max(0, $seen - $budget); $i--) {
                        $matches[] = $ring[$i % $budget];
                    }

                    if ($seen > $budget) {
                        // Older matches in this window were dropped: resume
                        // just below the oldest one returned.
                        $hi = $matches[array_key_last($matches)]->offset;
                        $hitLimit = true;
                        break;
                    }

                    $hi = $lo;

                    if (count($matches) >= $remaining && $hi > $start) {
                        $hitLimit = true;
                        break;
                    }
                }
            } finally {
                if ($stream !== null) {
                    fclose($stream);
                }
            }

            $complete = ! $timedOut && ! $hitLimit && $hi <= $start;
            $remaining -= count($matches);
            $stopped = ! $complete;

            $results[] = new FileSearchResult($file, new SearchResult(
                matches: $matches,
                nextCursor: $complete ? null : (new ByteCursor($hi, $fingerprint))->encode(),
                complete: $complete,
                engine: $this->engine->name(),
                truncated: $hitLimit,
                range: ['start' => $start, 'end' => $end],
                direction: SearchCriteria::BACKWARD,
            ));
        }

        return $results;
    }

    /**
     * Count matching records per time bucket without returning them.
     *
     * One multi-line record counts once, however many of its lines match,
     * the same unit search returns. The criteria limit does not apply; the
     * timeout does. A timed-out count is partial (`complete: false`) and
     * each unfinished file carries a forward cursor to continue from.
     *
     * @param  LogFile[]  $files
     */
    public function count(array $files, SearchCriteria $criteria, string $bucket = 'hour'): CountResult
    {
        if (! isset(self::BUCKET_FORMATS[$bucket])) {
            throw new InvalidArgumentException("Count bucket must be one of: minute, hour, day; got '{$bucket}'.");
        }

        // A count cursor is a forward start offset. A newest-first cursor is
        // an exclusive UPPER bound; applied here it would count the records
        // after it (the ones already seen) instead of failing loudly.
        if ($criteria->isBackward() && $criteria->fromOffset > 0) {
            throw new InvalidArgumentException('Count only resumes from forward cursors; got a newest-first (backward) cursor.');
        }

        $deadline = microtime(true) + $criteria->timeout;
        $tally = ['bucket' => $bucket, 'buckets' => [], 'total' => 0, 'undated' => 0, 'first' => null, 'last' => null];
        $perFile = [];
        $stopped = false;

        foreach (array_values($files) as $file) {
            $size = $this->source->size($file);
            $fingerprint = ByteCursor::fingerprint($size, $this->source->lastModified($file));

            if ($stopped || microtime(true) >= $deadline) {
                $stopped = true;
                $perFile[] = [
                    'file' => $file,
                    'total' => 0,
                    'complete' => false,
                    'cursor' => (new ByteCursor($criteria->fromOffset, $fingerprint))->encode(),
                    'range' => null,
                ];

                continue;
            }

            [$start, $end] = $this->range($file, $criteria, $size);
            $start = min($end, max($start, $criteria->fromOffset));

            $fileTotal = 0;
            $outcome = $this->engine->scanRange(
                $file,
                $criteria,
                $start,
                $end,
                $deadline,
                function (LogLine $record) use (&$tally, &$fileTotal): bool {
                    $fileTotal++;
                    $this->tally($tally, $record);

                    return true;
                },
                headersOnly: true,
            );

            $stopped = ! $outcome->completed;

            $perFile[] = [
                'file' => $file,
                'total' => $fileTotal,
                'complete' => $outcome->completed,
                'cursor' => $outcome->completed ? null : (new ByteCursor($outcome->resumeOffset, $fingerprint))->encode(),
                'range' => ['start' => $start, 'end' => $end],
            ];
        }

        ksort($tally['buckets'], SORT_STRING);

        return new CountResult(
            bucket: $tally['bucket'],
            buckets: $tally['buckets'],
            total: $tally['total'],
            undated: $tally['undated'],
            first: $tally['first'],
            last: $tally['last'],
            complete: ! $stopped,
            engine: $this->engine->name(),
            files: $perFile,
        );
    }

    /**
     * The byte range [start, end) of $file that can hold records matching the
     * criteria's time bounds and endOffset. The cursor (fromOffset) is NOT
     * applied here, because its meaning depends on the direction.
     *
     * Uses the time seek when a locator is configured. The range is widened
     * by the clock-skew margin; the exact date filter still runs on every
     * record.
     *
     * @return array{0:int,1:int}
     */
    public function range(LogFile $file, SearchCriteria $criteria, int $size): array
    {
        $start = 0;
        $end = $size;

        [$from, $to] = $criteria->timeBounds();
        $locator = $this->options->locator;

        if ($locator !== null && $size > 0 && ($from !== null || $to !== null)) {
            $margin = max(0, $this->options->timeSeekMargin);
            $stream = $this->source->reader($file);

            try {
                if ($from !== null) {
                    $start = $locator->offsetFor($stream, $size, $from - $margin);
                }
                if ($to !== null) {
                    $end = $locator->endOffsetFor($stream, $size, $to + $margin);
                }
            } finally {
                fclose($stream);
            }
        }

        if ($criteria->endOffset !== null) {
            $end = min($end, max(0, $criteria->endOffset));
        }

        $end = min($end, $size);
        $start = min(max(0, $start), $end);

        return [$start, $end];
    }

    /**
     * Start of the backward window ending at $hi: the header of the record
     * containing byte ($hi - window), so the window never splits a record.
     * Always < $hi, so every window makes progress.
     *
     * @param  resource  $stream
     */
    private function windowStart($stream, int $size, int $start, int $hi, int $window): int
    {
        $target = $hi - max(1, $window);
        if ($target <= $start) {
            return $start;
        }

        return max($start, $this->records->recordStartContaining($stream, $target, $size));
    }

    /**
     * @param  array{bucket:string,buckets:array<string,int>,total:int,undated:int,first:?int,last:?int}  $tally
     */
    private function tally(array &$tally, LogLine $record): void
    {
        $tally['total']++;

        $ts = Timestamp::parse($record->parsed?->datetime);
        if ($ts === null) {
            $tally['undated']++;

            return;
        }

        $tally['first'] = $tally['first'] === null ? $ts : min($tally['first'], $ts);
        $tally['last'] = $tally['last'] === null ? $ts : max($tally['last'], $ts);

        $key = date(self::BUCKET_FORMATS[$tally['bucket']], $ts);
        $tally['buckets'][$key] = ($tally['buckets'][$key] ?? 0) + 1;

        if (count($tally['buckets']) > CountResult::MAX_BUCKETS && $tally['bucket'] !== 'day') {
            $this->coarsen($tally);
        }
    }

    /**
     * Merge buckets one granularity up (minute -> hour -> day). The labels
     * are prefixes of each other, so re-keying is a substring.
     *
     * @param  array{bucket:string,buckets:array<string,int>}  $tally
     */
    private function coarsen(array &$tally): void
    {
        [$next, $length, $suffix] = $tally['bucket'] === 'minute' ? ['hour', 13, ':00'] : ['day', 10, ''];

        $merged = [];
        foreach ($tally['buckets'] as $key => $count) {
            $coarse = substr((string) $key, 0, $length).$suffix;
            $merged[$coarse] = ($merged[$coarse] ?? 0) + $count;
        }

        $tally['bucket'] = $next;
        $tally['buckets'] = $merged;

        if (count($merged) > CountResult::MAX_BUCKETS && $next !== 'day') {
            $this->coarsen($tally);
        }
    }

    private function unscanned(
        LogFile $file,
        SearchCriteria $criteria,
        string $fingerprint,
        bool $truncated,
        string $direction = SearchCriteria::FORWARD,
    ): FileSearchResult {
        // Never scanned: resumable from the requested offset rather than
        // pretending it had no matches.
        return new FileSearchResult($file, new SearchResult(
            matches: [],
            nextCursor: (new ByteCursor($criteria->fromOffset, $fingerprint))->encode(),
            complete: false,
            engine: $this->engine->name(),
            truncated: $truncated,
            range: null,
            direction: $direction,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Exceptions\SearchFailedException;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;

/**
 * Accelerated search backed by ripgrep (preferred) or grep.
 *
 * Both binaries emit a byte offset per match (`--byte-offset` / `grep -b`),
 * which becomes our pagination cursor directly. Output is consumed incrementally
 * from the child process and the process is killed the moment the result limit
 * is reached, so neither the file nor the full result set is ever buffered.
 *
 * RANGED SCANS: rg/grep have no "start at byte N" flag, so a scan of a byte
 * range [start, end) feeds exactly that slice to the process on stdin, in
 * 1 MB chunks read with fread, and adds `start` back to every reported
 * offset. A whole file that fits in one piece passes the path instead, so
 * the engine can read the file directly.
 *
 * PIECES: a range is scanned in consecutive pieces, one process each: 1 MB
 * first (or ScanOptions::$forwardPiece if smaller), doubling up to 1 GB (or
 * $forwardPiece if larger). rg/grep can't report how far a timed-out process
 * got, so without pieces a scan that times out before its first match would
 * hand back the cursor it was given, and a client paging until done would
 * re-scan the same bytes forever. With pieces, the resume point is at least
 * the end of the last completed piece, and the small first piece means even
 * a slow call (busy disk, idle-priority IO) completes one.
 *
 * MULTI-FILE: searchMany() runs the files in turn through SearchRunner, one
 * piece-wise scanRange() per file, under one shared limit and deadline. It
 * deliberately does not hand every file to a single rg/grep process: such a
 * process can't say which files it finished when it times out, so every
 * file's cursor would stay where it started.
 *
 * A grep/rg hit is one PHYSICAL line, which may be a record's header or one
 * of its continuation lines (stack trace, pretty-printed context) — either
 * way, RecordAssembler reconstructs the whole record it belongs to before it
 * counts as a match, so a multi-line exception never renders as N separate,
 * header-less rows. Hits arrive in file order, so two hits in one record are
 * de-duped by remembering only the last record header.
 *
 * SECURITY: arguments are passed as an array to Symfony Process (no shell), so
 * the query string is never interpreted by a shell — no command injection.
 */
final class CommandLineSearcher implements RangeScanner
{
    private const SLICE_CHUNK = 1048576;

    /**
     * First forward piece of a call, in bytes (or ScanOptions::$forwardPiece
     * if smaller). Small enough to finish inside any sane timeout, so every
     * call moves the cursor; the same idea as SearchRunner's first backward
     * window.
     */
    private const FIRST_FORWARD_PIECE = 1024 * 1024;

    /** Forward pieces double up to this (or ScanOptions::$forwardPiece if larger). */
    private const MAX_FORWARD_PIECE = 1024 * 1024 * 1024;

    private const PIECE_DONE = 'done';
    private const PIECE_STOPPED = 'stopped';
    private const PIECE_TIMED_OUT = 'timed_out';

    /**
     * @param  string[]  $commandPrefix  argv prepended to every invocation,
     *   e.g. ['nice', '-n', '10'] (see SearchManager::commandPrefix())
     */
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly RecordAssembler $records,
        private readonly string $engine,   // 'ripgrep' | 'grep'
        private readonly string $binary,
        private readonly bool $enabled,
        private readonly ScanOptions $options = new ScanOptions(),
        private readonly array $commandPrefix = [],
    ) {
    }

    public function name(): string
    {
        return $this->engine;
    }

    public function available(LogFile $file): bool
    {
        if (! $this->enabled) {
            return false;
        }

        // Needs a real on-disk path the host can shell out to.
        if ($this->source->localPath($file) === null) {
            return false;
        }

        return (new ExecutableFinder())->find($this->binary, null, ['/usr/local/bin', '/opt/homebrew/bin']) !== null
            || is_executable($this->binary);
    }

    public function search(LogFile $file, SearchCriteria $criteria): SearchResult
    {
        return $this->searchMany([$file], $criteria)[0]->result;
    }

    /**
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function searchMany(array $files, SearchCriteria $criteria): array
    {
        if ($files === []) {
            return [];
        }

        // One file at a time, in pieces, under the shared limit and deadline
        // (see MULTI-FILE above): every file the budget reaches reports how
        // far it got, and the rest come back unscanned and resumable.
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
        $path = $this->source->localPath($file);
        $size = $this->source->size($file);
        $end = min($end, $size);

        if ($path === null || $start >= $end) {
            // available() gates on a resolvable path; an empty range has nothing to scan.
            return ScanOutcome::completed(max($start, $end));
        }

        // Dedupe state shared by every piece, so a record whose hits straddle
        // a piece boundary is still handled once, exactly as one scan would.
        $state = ['resume' => $start, 'lastHeader' => -1, 'lastEnd' => -1, 'stream' => null];
        $configured = max(1, $this->options->forwardPiece);
        $piece = min(self::FIRST_FORWARD_PIECE, $configured);
        $maxPiece = max($configured, self::MAX_FORWARD_PIECE);
        $lo = $start;

        try {
            while ($lo < $end) {
                $budget = $deadline - microtime(true);
                if ($budget <= 0) {
                    return new ScanOutcome($state['resume'], false, true);
                }

                $hi = $this->pieceEnd($file, $state, $lo, $end, $size, $piece);
                $piece = min($piece * 2, $maxPiece);

                $status = $this->scanPiece($file, $path, $criteria, $start, $lo, $hi, $end, $size, $budget, $onMatch, $headersOnly, $state);

                if ($status !== self::PIECE_DONE) {
                    return new ScanOutcome($state['resume'], false, $status === self::PIECE_TIMED_OUT);
                }

                // Everything below $hi has been scanned. rg/grep can't say
                // how far a timed-out process read, so this is what lets a
                // scan that times out before its first match still move the
                // cursor instead of handing back the one it was given.
                $state['resume'] = max($state['resume'], $hi);
                $lo = $hi;
            }
        } finally {
            if ($state['stream'] !== null) {
                fclose($state['stream']);
            }
        }

        return ScanOutcome::completed($end);
    }

    /**
     * End of the forward piece starting at $lo: the header of the record
     * containing byte ($lo + piece), so resuming there never starts inside a
     * record. Falls back to the next line start when one record is larger
     * than the whole piece. Always > $lo, so every piece makes progress.
     *
     * @param  array{stream:resource|null}  $state
     */
    private function pieceEnd(LogFile $file, array &$state, int $lo, int $end, int $size, int $piece): int
    {
        $target = $lo + $piece;
        if ($target >= $end) {
            return $end;
        }

        $state['stream'] ??= $this->source->reader($file);
        $hi = $this->records->recordStartContaining($state['stream'], $target, $size);

        return $hi > $lo ? $hi : $this->nextLineStart($state['stream'], $target, $end);
    }

    /**
     * @param  resource  $stream
     */
    private function nextLineStart($stream, int $offset, int $end): int
    {
        fseek($stream, $offset, SEEK_SET);

        while ($offset < $end) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $nl = strpos($chunk, "\n");
            if ($nl !== false) {
                return min($end, $offset + $nl + 1);
            }
            $offset += strlen($chunk);
        }

        return $end;
    }

    /**
     * Run one rg/grep process over [$lo, $hi), handing matching records to
     * $onMatch. Records are resolved against the whole scan's [$start, $end),
     * not the piece, so piece boundaries never change what matches.
     *
     * @param  array{resume:int,lastHeader:int,lastEnd:int,stream:resource|null}  $state
     * @return string  one of the PIECE_* constants
     */
    private function scanPiece(
        LogFile $file,
        string $path,
        SearchCriteria $criteria,
        int $start,
        int $lo,
        int $hi,
        int $end,
        int $size,
        float $budget,
        callable $onMatch,
        bool $headersOnly,
        array &$state,
    ): string {
        // A piece covering the whole file passes the path, so the engine
        // reads the file directly; anything else is fed on stdin.
        $whole = $lo === 0 && $hi === $size;

        $process = new Process($this->command($criteria, [$whole ? $path : '-'], false));
        $process->setTimeout($budget);
        if (! $whole) {
            $process->setInput($this->slice($file, $lo, $hi));
        }

        $buffer = '';
        $stderr = '';
        $sawOutput = false;
        $status = self::PIECE_DONE;

        $process->start();

        try {
            foreach ($process as $type => $data) {
                if ($type !== Process::OUT) {
                    $stderr = substr($stderr.$data, 0, 4096);

                    continue;
                }

                $sawOutput = true;
                $buffer .= $data;
                $pos = 0;

                while (($nl = strpos($buffer, "\n", $pos)) !== false) {
                    $rawLine = substr($buffer, $pos, $nl - $pos);
                    $pos = $nl + 1;

                    // Bounded by $hi: a whole-file scan reads the live path,
                    // so lines appended after the size snapshot are dropped.
                    $hit = $this->parseOutputLine($rawLine, $lo, $hi);
                    // A hit inside the record just handled is part of it:
                    // skip it before paying for a header lookup. This is
                    // also what keeps header-less files from yielding one
                    // overlapping record per matching line.
                    if ($hit === null || $hit->offset < $state['lastEnd']) {
                        continue;
                    }

                    $state['stream'] ??= $this->source->reader($file);
                    // Folds stop at $end, so a window never re-reads the
                    // next window's lines (matters only where $end is not a
                    // record header: header-less or over-long records).
                    $record = $this->recordFor($state['stream'], $hit, $end, $headersOnly);
                    if ($record === null) {
                        continue;
                    }

                    // Remember how far this record reaches even when it is
                    // skipped below, so its remaining hits are skipped cheaply.
                    $state['lastEnd'] = max($state['lastEnd'], $record->endOffset);

                    // Skip records that start before the range (they belong
                    // to an earlier page or window) and repeat hits in a
                    // record already handled.
                    if ($record->offset < $start || $record->offset <= $state['lastHeader']) {
                        continue;
                    }
                    $state['lastHeader'] = $record->offset;

                    if (! $criteria->passesStructuredFilters($record)) {
                        continue;
                    }

                    $state['resume'] = max($state['resume'], $record->endOffset);

                    if ($onMatch($record) === false) {
                        $status = self::PIECE_STOPPED;
                        break 2;
                    }
                }

                $buffer = substr($buffer, $pos);
            }
        } catch (ProcessTimedOutException) {
            $status = self::PIECE_TIMED_OUT;
        }

        if ($process->isRunning()) {
            $process->stop(1);
        }

        if ($status === self::PIECE_DONE) {
            $this->assertSucceeded($process, $stderr, $sawOutput, $whole ? [$path] : []);
        }

        return $status;
    }

    /**
     * The full argv: priority prefix (nice/ionice) + engine command.
     *
     * The searcher itself always runs one input per process without
     * filenames; $withFilename (prefix each hit with "path:") is kept for
     * callers that build their own multi-path invocation.
     *
     * @param  string[]  $paths
     * @return string[]
     */
    public function command(SearchCriteria $criteria, array $paths, bool $withFilename): array
    {
        return [...$this->commandPrefix, ...$this->buildCommand($criteria, $paths, $withFilename)];
    }

    /**
     * rg and grep both exit 0 (matches) or 1 (no matches); anything else is
     * an error (bad regex, unreadable input, a binary that did not run).
     * Only called for a process that ran to its end on its own.
     *
     * Exit 2 is also what both engines return when ONE input failed (rotated
     * away mid-search, not readable) while the others were searched normally.
     * That is not fatal: if the engine produced output, or every error line
     * names one of the searched files, the results already collected stand.
     * Only an error that prevented the search itself (bad pattern, bad
     * option) throws.
     *
     * The message reaches API clients, so searched paths are reduced to
     * their basename, the same name the file list already exposes.
     *
     * @param  string[]  $paths  on-disk paths given to the engine
     */
    private function assertSucceeded(Process $process, string $stderr, bool $sawOutput, array $paths): void
    {
        $process->wait();
        $code = $process->getExitCode();

        if ($code === 0 || $code === 1) {
            return;
        }

        if ($code === 2 && ($sawOutput || $this->onlyPerFileErrors($stderr, $paths))) {
            return;
        }

        throw SearchFailedException::engine($this->engine, $code, $this->redactPaths($stderr, $paths));
    }

    /**
     * @param  string[]  $paths
     */
    private function onlyPerFileErrors(string $stderr, array $paths): bool
    {
        $lines = array_filter(array_map('trim', explode("\n", $stderr)), static fn (string $l) => $l !== '');
        if ($lines === [] || $paths === []) {
            return false;
        }

        foreach ($lines as $line) {
            $named = false;
            foreach ($paths as $path) {
                if (str_contains($line, $path)) {
                    $named = true;
                    break;
                }
            }
            if (! $named) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string[]  $paths
     */
    private function redactPaths(string $stderr, array $paths): string
    {
        // Longest first, so a path is never half-replaced by a shorter one.
        usort($paths, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($paths as $path) {
            $stderr = str_replace($path, basename($path), $stderr);
        }

        // Anything else under the same directories (e.g. a sibling file).
        foreach (array_unique(array_map('dirname', $paths)) as $dir) {
            if ($dir !== '' && $dir !== '.' && $dir !== '/') {
                $stderr = str_replace(rtrim($dir, '/').'/', '', $stderr);
            }
        }

        return $stderr;
    }

    /**
     * Read [start, end) of the file in fixed chunks, for the process's stdin.
     * Symfony Process pulls one chunk at a time as the pipe drains, so at
     * most one chunk is in memory.
     *
     * @return \Generator<int,string>
     */
    private function slice(LogFile $file, int $start, int $end): \Generator
    {
        $stream = $this->source->reader($file);

        try {
            fseek($stream, $start, SEEK_SET);
            $left = $end - $start;

            while ($left > 0) {
                $chunk = fread($stream, min(self::SLICE_CHUNK, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $left -= strlen($chunk);

                yield $chunk;
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Resolve a hit to the record it belongs to.
     *
     * In header mode (counting), a hit on a header line is parsed on its own
     * with no file access. Any other hit is folded exactly as search folds
     * it: an orphan line plus its following lines, or a line past the
     * continuation cap, must be the same single record in both, or count and
     * search disagree. The fold also gives the record's true end, so the
     * caller skips the rest of its hits without another backward walk.
     *
     * @param  resource  $stream
     */
    private function recordFor($stream, LogLine $hit, int $size, bool $headersOnly): ?LogLine
    {
        if ($headersOnly && $this->records->isHeader($hit->raw)) {
            return $this->records->parseHeaderLine($hit->offset, $hit->endOffset, $hit->raw);
        }

        return $this->records->readRecordAt($stream, $hit->offset, $size);
    }

    /**
     * The engine argv, without the priority prefix. `$paths` may be ['-'] to
     * read stdin.
     *
     * @param  string[]  $paths
     * @return string[]
     */
    private function buildCommand(SearchCriteria $criteria, array $paths, bool $withFilename): array
    {
        $query = $criteria->hasTextQuery() ? $criteria->query : '';

        if ($this->engine === 'ripgrep') {
            $cmd = [
                $this->binary,
                '--no-config',
                '--color', 'never',
                '--no-heading',
                $withFilename ? '--with-filename' : '--no-filename',
                '--no-line-number',
                '--byte-offset',
                '--text',
            ];
            if (! $criteria->caseSensitive) {
                $cmd[] = '-i';
            }
            if (! $criteria->regex) {
                $cmd[] = '-F';
            }
            // Always -e: a query starting with "-" (e.g. "--pre=cmd") must be
            // the pattern, never an rg option.
            $cmd[] = '-e';
            $cmd[] = $query;
            $cmd[] = '--';

            return array_merge($cmd, $paths);
        }

        // grep
        $cmd = [$this->binary, '-b', '-a'];
        if ($withFilename) {
            // grep only prefixes filenames by default when given >1 file; force
            // it so one path parses identically to several.
            $cmd[] = '-H';
        }
        if (! $criteria->caseSensitive) {
            $cmd[] = '-i';
        }
        $cmd[] = $criteria->regex ? '-E' : '-F';
        $cmd[] = '--';
        $cmd[] = $query;

        return array_merge($cmd, $paths);
    }

    /**
     * Both engines print "OFFSET:line". Split on the first colon. Offsets are
     * relative to what the engine read, so a stdin slice adds its $base back.
     * A hit at or past $size (written after the size snapshot) is dropped.
     */
    private function parseOutputLine(string $rawLine, int $base, int $size): ?LogLine
    {
        $colon = strpos($rawLine, ':');
        if ($colon === false) {
            return null;
        }

        $offsetPart = substr($rawLine, 0, $colon);
        if (! ctype_digit($offsetPart)) {
            return null;
        }

        $offset = $base + (int) $offsetPart;
        if ($offset >= $size) {
            return null;
        }
        $content = substr($rawLine, $colon + 1);
        $end = min($size, $offset + strlen($content) + 1);

        return new LogLine($offset, $end, rtrim($content, "\r"));
    }
}

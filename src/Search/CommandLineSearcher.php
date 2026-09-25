<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Support\ByteCursor;
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
 * A grep/rg hit is one PHYSICAL line, which may be a record's header or one
 * of its continuation lines (stack trace, pretty-printed context) — either
 * way, RecordAssembler reconstructs the whole record it belongs to before it
 * counts as a match, so a multi-line exception never renders as N separate,
 * header-less rows. Records are de-duped per file (`seenHeaders`) since two
 * hits can land in the same record.
 *
 * SECURITY: arguments are passed as an array to Symfony Process (no shell), so
 * the query string is never interpreted by a shell — no command injection.
 */
final class CommandLineSearcher implements Searcher
{
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly RecordAssembler $records,
        private readonly string $engine,   // 'ripgrep' | 'grep'
        private readonly string $binary,
        private readonly bool $enabled,
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
        return $this->run([$file], $criteria)[0]->result;
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

        return $this->run(array_values($files), $criteria);
    }

    /**
     * The single engine invocation behind both public entry points.
     *
     * Every file is handed to ONE rg/grep process, so a 5-file search costs one
     * fork rather than five, and the limit/timeout below is spent across the
     * whole batch. Output is consumed incrementally and the process is killed
     * the moment the budget runs out, so nothing is ever fully buffered.
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    private function run(array $files, SearchCriteria $criteria): array
    {
        $multi = count($files) > 1;

        // Per-file bookkeeping, keyed by the resolved on-disk path because that
        // is what rg/grep echo back on each output line.
        $paths = [];
        $state = [];

        foreach ($files as $index => $file) {
            $path = $this->source->localPath($file);
            if ($path === null) {
                // Should not happen: available() gates on a resolvable path.
                $state[$index] = ['file' => $file, 'path' => null, 'size' => 0, 'mtime' => 0, 'matches' => [], 'lastOffset' => $criteria->fromOffset, 'stream' => null, 'seenHeaders' => []];

                continue;
            }

            $paths[$path] = $index;
            $state[$index] = [
                'file' => $file,
                'path' => $path,
                'size' => $this->source->size($file),
                'mtime' => $this->source->lastModified($file),
                'matches' => [],
                'lastOffset' => $criteria->fromOffset,
                'stream' => null,
                'seenHeaders' => [],
            ];
        }

        // Longest path first, so /a/b.log.1 is never mistaken for /a/b.log.
        $prefixes = array_keys($paths);
        usort($prefixes, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $process = new Process($this->buildCommand($criteria, $prefixes, $multi));
        $process->setTimeout($criteria->timeout);

        $total = 0;
        $buffer = '';
        $hitLimit = false;
        $timedOut = false;

        $process->start();

        try {
            foreach ($process as $type => $data) {
                if ($type !== Process::OUT) {
                    continue;
                }

                $buffer .= $data;

                while (($nl = strpos($buffer, "\n")) !== false) {
                    $rawLine = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);

                    // In multi-file mode each line is prefixed with its filename;
                    // that prefix is how a match is attributed back to its file.
                    $index = 0;
                    if ($multi) {
                        $attributed = $this->attribute($rawLine, $prefixes, $paths);
                        if ($attributed === null) {
                            continue;
                        }
                        [$index, $rawLine] = $attributed;
                    }

                    $hit = $this->parseOutputLine($rawLine, $state[$index]['size']);
                    if ($hit === null) {
                        continue;
                    }

                    // The hit may be anywhere inside a multi-line record (its
                    // header, or one of its continuation lines) — reconstruct
                    // the whole record it belongs to before treating it as a
                    // match, so a stack trace never surfaces as N separate rows.
                    $record = $this->records->readRecordAt(
                        $this->streamFor($state, $index),
                        $hit->offset,
                        $state[$index]['size'],
                    );
                    if ($record === null) {
                        continue;
                    }

                    // Resume support: skip anything whose record already ended
                    // at or before the requested offset (reported on an
                    // earlier page). Keyed off the record's own start, not the
                    // raw hit, since reconstruction can walk backward past it.
                    if ($record->offset < $criteria->fromOffset) {
                        continue;
                    }

                    // Two hits (e.g. the header and a stack frame) can resolve
                    // to the same record — count it once.
                    if (isset($state[$index]['seenHeaders'][$record->offset])) {
                        continue;
                    }

                    if (! $this->passesStructuredFilters($record, $criteria)) {
                        continue;
                    }

                    $state[$index]['seenHeaders'][$record->offset] = true;
                    $state[$index]['matches'][] = $record;
                    $state[$index]['lastOffset'] = $record->endOffset;
                    $total++;

                    if ($total >= $criteria->limit) {
                        $hitLimit = true;
                        break 2;
                    }
                }
            }
        } catch (ProcessTimedOutException) {
            // The shared wall-clock budget is spent: keep what we have and tell
            // the caller the batch is resumable rather than blowing up.
            $timedOut = true;
        } finally {
            foreach ($state as $entry) {
                if ($entry['stream'] !== null) {
                    fclose($entry['stream']);
                }
            }
        }

        if ($process->isRunning()) {
            $process->stop(1);
        }

        $stopped = $hitLimit || $timedOut;

        $results = [];
        foreach ($state as $entry) {
            $complete = ! $stopped;

            $results[] = new FileSearchResult(
                file: $entry['file'],
                result: new SearchResult(
                    matches: $entry['matches'],
                    nextCursor: $complete
                        ? null
                        : (new ByteCursor(
                            $entry['lastOffset'],
                            ByteCursor::fingerprint($entry['size'], $entry['mtime']),
                        ))->encode(),
                    complete: $complete,
                    engine: $this->engine,
                    truncated: $hitLimit,
                ),
            );
        }

        return $results;
    }

    /**
     * Lazily open (and cache in $state) the read handle used to reconstruct
     * full records around this file's hits. Most files in a batch never have
     * a hit, so this avoids opening a stream per file up front.
     *
     * @param  array<int,array{file:LogFile,stream:resource|null,...}>  $state
     * @return resource
     */
    private function streamFor(array &$state, int $index)
    {
        if ($state[$index]['stream'] === null) {
            $state[$index]['stream'] = $this->source->reader($state[$index]['file']);
        }

        return $state[$index]['stream'];
    }

    /**
     * Strip the "path:" prefix an engine puts in front of a multi-file match and
     * return the index of the file it belongs to.
     *
     * @param  string[]  $prefixes  paths, longest first
     * @param  array<string,int>  $paths  path => file index
     * @return array{0:int,1:string}|null  [file index, line without the prefix]
     */
    private function attribute(string $line, array $prefixes, array $paths): ?array
    {
        foreach ($prefixes as $path) {
            if (str_starts_with($line, $path.':')) {
                return [$paths[$path], substr($line, strlen($path) + 1)];
            }
        }

        return null;
    }

    /**
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
            $cmd[] = $criteria->regex ? '-e' : '-F';
            $cmd[] = $query === '' ? '' : $query;
            $cmd[] = '--';

            return array_merge($cmd, $paths);
        }

        // grep
        $cmd = [$this->binary, '-b', '-a'];
        if ($withFilename) {
            // grep only prefixes filenames by default when given >1 file; force
            // it so a one-file batch parses identically to a many-file one.
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
     * Both engines print "OFFSET:line". Split on the first colon.
     */
    private function parseOutputLine(string $rawLine, int $size): ?LogLine
    {
        $colon = strpos($rawLine, ':');
        if ($colon === false) {
            return null;
        }

        $offsetPart = substr($rawLine, 0, $colon);
        if (! ctype_digit($offsetPart)) {
            return null;
        }

        $offset = (int) $offsetPart;
        $content = substr($rawLine, $colon + 1);
        $end = min($size, $offset + strlen($content) + 1);

        return new LogLine($offset, $end, $content);
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

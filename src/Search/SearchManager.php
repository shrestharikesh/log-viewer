<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Reading\LineScanner;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Reading\TimeOffsetLocator;
use Vendor\LogExplorer\Support\LogFile;

/**
 * Picks the best available search backend for a file and delegates to it.
 *
 * In "auto" mode it prefers ripgrep, then grep, then the pure-PHP streamer —
 * but only for backends that are actually available for the given file (binary
 * present, local path resolvable). Remote sources transparently fall back to
 * streaming. This keeps the controller backend-agnostic.
 *
 * Every engine it builds gets the same ScanOptions (time seek, clock-skew
 * margin, newest-first window) from the `log-explorer.search` config, and the
 * CLI engines run under the configured nice/ionice prefix.
 */
final class SearchManager
{
    /** @var array<string,string[]|null>  checked priority wrappers (nice, ionice), per worker */
    private static array $wrappers = [];

    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly LineScanner $scanner,
        private readonly ParserManager $parsers,
        private readonly Config $config,
    ) {
    }

    public function search(LogFile $file, SearchCriteria $criteria): SearchResult
    {
        return $this->resolve($file, $criteria)->search($file, $criteria);
    }

    /**
     * Search a batch of files under one shared limit/timeout budget.
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function searchMany(array $files, SearchCriteria $criteria): array
    {
        if ($files === []) {
            return [];
        }

        return $this->resolveMany($files, $criteria)->searchMany($files, $criteria);
    }

    /**
     * Count matching records per time bucket ('minute' | 'hour' | 'day')
     * across the (time-seek narrowed) range of each file, without returning
     * lines. One multi-line record counts once. Shares one timeout budget
     * across the batch; the criteria limit does not apply.
     *
     * @param  LogFile[]  $files
     */
    public function count(array $files, SearchCriteria $criteria, string $bucket = 'hour'): CountResult
    {
        $files = array_values($files);
        $engine = $files === [] ? $this->make('stream') : $this->resolveMany($files, $criteria);

        if (! $engine instanceof RangeScanner) {
            $engine = $this->make('stream');
        }

        return (new SearchRunner($engine, $this->source, $this->records(), $this->options()))
            ->count($files, $criteria, $bucket);
    }

    public function resolve(LogFile $file, ?SearchCriteria $criteria = null): Searcher
    {
        return $this->resolveMany([$file], $criteria);
    }

    /**
     * Pick a backend that can serve EVERY file in the batch — a batch is one
     * process, so a single remote/unreadable file demotes the whole batch to
     * the streamer rather than silently dropping that file.
     *
     * @param  LogFile[]  $files
     */
    public function resolveMany(array $files, ?SearchCriteria $criteria = null): Searcher
    {
        // A filter-only request (level/date, no text) is handled by the PHP
        // streamer: it parses every line and filters structurally, which the
        // CLI engines can't do and which an empty grep/rg pattern handles poorly.
        if ($criteria !== null && ! $criteria->hasTextQuery()) {
            return $this->make('stream');
        }

        $mode = (string) $this->config->get('log-explorer.search.mode', 'auto');

        $candidates = match ($mode) {
            'stream' => ['stream'],
            'ripgrep' => ['ripgrep', 'stream'],
            'grep' => ['grep', 'stream'],
            default => ['ripgrep', 'grep', 'stream'],
        };

        foreach ($candidates as $name) {
            $searcher = $this->make($name);
            if ($searcher !== null && $this->availableForAll($searcher, $files)) {
                return $searcher;
            }
        }

        // Streamer is always available; this is just a safety net.
        return $this->make('stream');
    }

    /**
     * @param  LogFile[]  $files
     */
    private function availableForAll(Searcher $searcher, array $files): bool
    {
        foreach ($files as $file) {
            if (! $searcher->available($file)) {
                return false;
            }
        }

        return true;
    }

    private function make(string $name): ?Searcher
    {
        $records = $this->records();
        $options = $this->options();

        return match ($name) {
            'stream' => new StreamSearcher($this->source, $records, $options),
            'ripgrep' => new CommandLineSearcher(
                $this->source,
                $records,
                'ripgrep',
                (string) $this->config->get('log-explorer.search.ripgrep.binary', 'rg'),
                (bool) $this->config->get('log-explorer.search.ripgrep.enabled', true),
                $options,
                $this->commandPrefix(),
            ),
            'grep' => new CommandLineSearcher(
                $this->source,
                $records,
                'grep',
                (string) $this->config->get('log-explorer.search.grep.binary', 'grep'),
                (bool) $this->config->get('log-explorer.search.grep.enabled', true),
                $options,
                $this->commandPrefix(),
            ),
            default => null,
        };
    }

    private function records(): RecordAssembler
    {
        return new RecordAssembler(
            $this->scanner,
            $this->parsers,
            (int) $this->config->get('log-explorer.parsing.max_continuation_lines', 200),
        );
    }

    public function options(): ScanOptions
    {
        $seek = (bool) $this->config->get('log-explorer.search.time_seek', true);

        return new ScanOptions(
            locator: $seek ? new TimeOffsetLocator($this->scanner, $this->parsers) : null,
            timeSeekMargin: max(0, (int) $this->config->get('log-explorer.search.time_seek_margin', 300)),
            backwardWindow: max(1, (int) $this->config->get('log-explorer.search.backward_window', 64 * 1024 * 1024)),
            forwardPiece: max(1, (int) $this->config->get('log-explorer.search.forward_piece', 64 * 1024 * 1024)),
        );
    }

    /**
     * argv prefix that runs rg/grep at lower priority, so a big search does
     * not starve the app it is diagnosing:
     *
     *   - `nice -n N` when `search.nice` is a positive int (true = 10);
     *   - `ionice -c3 -t` (idle I/O class; -t = run anyway if it can't be
     *     set) on Linux when `search.ionice` is on.
     *
     * Each wrapper is used only if its binary is found AND a trial run of it
     * succeeds (BusyBox's ionice, common on Alpine images, rejects `-t`; a
     * wrapper that fails would make every search silently return nothing).
     * The check runs once per worker. Still an argv array, so no shell.
     *
     * @return string[]
     */
    public function commandPrefix(): array
    {
        $nice = $this->config->get('log-explorer.search.nice', 10);
        $nice = $nice === true ? 10 : (is_numeric($nice) ? (int) $nice : 0);

        $prefix = [];

        if ($this->config->get('log-explorer.search.ionice', true) && PHP_OS_FAMILY === 'Linux') {
            $prefix = self::workingWrapper('ionice', ['-c3', '-t']) ?? [];
        }

        if ($nice > 0) {
            $prefix = [...$prefix, ...(self::workingWrapper('nice', ['-n', (string) min($nice, 19)]) ?? [])];
        }

        return $prefix;
    }

    /**
     * [binary, ...args] if `binary args true` exits 0 on this host, else null.
     *
     * @param  string[]  $args
     * @return string[]|null
     */
    private static function workingWrapper(string $name, array $args): ?array
    {
        $key = $name.' '.implode(' ', $args);

        if (! array_key_exists($key, self::$wrappers)) {
            $binary = (new ExecutableFinder())->find($name, null, ['/usr/bin', '/bin', '/usr/local/bin']);
            $true = (new ExecutableFinder())->find('true', null, ['/usr/bin', '/bin']);
            $works = false;

            if ($binary !== null && $true !== null) {
                try {
                    $trial = new Process([$binary, ...$args, $true]);
                    $trial->setTimeout(5);
                    $works = $trial->run() === 0;
                } catch (\Throwable) {
                    $works = false;
                }
            }

            self::$wrappers[$key] = $works ? [$binary, ...$args] : null;
        }

        return self::$wrappers[$key];
    }
}

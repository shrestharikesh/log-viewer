<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Illuminate\Contracts\Config\Repository as Config;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Reading\LineScanner;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Support\LogFile;

/**
 * Picks the best available search backend for a file and delegates to it.
 *
 * In "auto" mode it prefers ripgrep, then grep, then the pure-PHP streamer —
 * but only for backends that are actually available for the given file (binary
 * present, local path resolvable). Remote sources transparently fall back to
 * streaming. This keeps the controller backend-agnostic.
 */
final class SearchManager
{
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
        $records = new RecordAssembler(
            $this->scanner,
            $this->parsers,
            (int) $this->config->get('log-explorer.parsing.max_continuation_lines', 200),
        );

        return match ($name) {
            'stream' => new StreamSearcher($this->source, $records),
            'ripgrep' => new CommandLineSearcher(
                $this->source,
                $records,
                'ripgrep',
                (string) $this->config->get('log-explorer.search.ripgrep.binary', 'rg'),
                (bool) $this->config->get('log-explorer.search.ripgrep.enabled', true),
            ),
            'grep' => new CommandLineSearcher(
                $this->source,
                $records,
                'grep',
                (string) $this->config->get('log-explorer.search.grep.binary', 'grep'),
                (bool) $this->config->get('log-explorer.search.grep.enabled', true),
            ),
            default => null,
        };
    }
}

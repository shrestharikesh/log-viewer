<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Support\LogFile;

interface Searcher
{
    public function name(): string;

    /**
     * Whether this backend can run for the given file right now (binary
     * present, local path available, enabled in config, ...).
     */
    public function available(LogFile $file): bool;

    public function search(LogFile $file, SearchCriteria $criteria): SearchResult;

    /**
     * Search several files under ONE shared budget.
     *
     * `$criteria->limit` and `$criteria->timeout` apply to the batch as a whole,
     * not per file — otherwise selecting N files would multiply the worst-case
     * cost of a request by N. Results are returned in the order the files were
     * given, each still attributed to its own LogFile.
     *
     * @param  LogFile[]  $files
     * @return FileSearchResult[]
     */
    public function searchMany(array $files, SearchCriteria $criteria): array;
}

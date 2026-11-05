<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Support\ByteCursor;

/**
 * Shared query-string -> SearchCriteria mapping for the search and count
 * endpoints: q, regex, case, level, from, to, cursor, direction.
 */
trait BuildsSearchCriteria
{
    protected function criteriaFrom(Request $request, int $limit): SearchCriteria
    {
        return new SearchCriteria(
            query: (string) $request->query('q', ''),
            regex: $request->boolean('regex'),
            caseSensitive: $request->boolean('case'),
            level: $request->query('level') ?: null,
            dateFrom: $request->query('from') ?: null,
            dateTo: $request->query('to') ?: null,
            limit: $limit,
            fromOffset: ByteCursor::decode($request->query('cursor'))?->offset ?? 0,
            timeout: (int) config('log-explorer.search.timeout', 30),
            direction: $request->query('direction') === SearchCriteria::BACKWARD
                ? SearchCriteria::BACKWARD
                : SearchCriteria::FORWARD,
        );
    }
}

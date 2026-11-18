<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Vendor\LogExplorer\Http\QueryInput;
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
        $query = QueryInput::string($request, 'q', '');

        // The query becomes an rg/grep argument; a NUL byte cannot be passed
        // to a process at all, so reject it up front with a clear message.
        if (str_contains($query, "\0")) {
            abort(422, 'The search query must not contain NUL bytes.');
        }

        return new SearchCriteria(
            query: $query,
            regex: $request->boolean('regex'),
            caseSensitive: $request->boolean('case'),
            level: QueryInput::string($request, 'level') ?: null,
            dateFrom: QueryInput::string($request, 'from') ?: null,
            dateTo: QueryInput::string($request, 'to') ?: null,
            limit: $limit,
            fromOffset: ByteCursor::decode(QueryInput::string($request, 'cursor'))?->offset ?? 0,
            timeout: (int) config('log-explorer.search.timeout', 30),
            direction: QueryInput::string($request, 'direction') === SearchCriteria::BACKWARD
                ? SearchCriteria::BACKWARD
                : SearchCriteria::FORWARD,
        );
    }
}

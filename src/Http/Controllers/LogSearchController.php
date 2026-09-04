<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchManager;
use Vendor\LogExplorer\Support\ByteCursor;

/**
 * GET {prefix}/api/search — streamed search across a (possibly huge) file.
 *
 * Query: file, q, regex, case, level, from, to, cursor, limit
 */
final class LogSearchController
{
    use ResolvesLogFile;

    public function __invoke(Request $request, SearchManager $search): JsonResponse
    {
        $file = $this->resolveFile($request);

        $fromOffset = ByteCursor::decode($request->query('cursor'))?->offset ?? 0;

        $criteria = new SearchCriteria(
            query: (string) $request->query('q', ''),
            regex: $request->boolean('regex'),
            caseSensitive: $request->boolean('case'),
            level: $request->query('level') ?: null,
            dateFrom: $request->query('from') ?: null,
            dateTo: $request->query('to') ?: null,
            limit: min($this->clampLimit($request), (int) config('log-explorer.search.max_results', 1000)),
            fromOffset: $fromOffset,
            timeout: (int) config('log-explorer.search.timeout', 30),
        );

        return response()->json($search->search($file, $criteria));
    }
}

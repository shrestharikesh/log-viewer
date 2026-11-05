<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\LogExplorer\Exceptions\SearchFailedException;
use Vendor\LogExplorer\Http\Controllers\Concerns\BuildsSearchCriteria;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Search\SearchManager;

/**
 * GET {prefix}/api/search — streamed search across a (possibly huge) file.
 *
 * Query: file, q, regex, case, level, from, to, cursor, limit,
 *        direction (forward = oldest first, default | backward = newest first)
 *
 * `from`/`to` are turned into a byte range by binary search before scanning,
 * so a recent time window costs the window, not the file.
 */
final class LogSearchController
{
    use BuildsSearchCriteria;
    use ResolvesLogFile;

    public function __invoke(Request $request, SearchManager $search): JsonResponse
    {
        $file = $this->resolveFile($request);

        $criteria = $this->criteriaFrom(
            $request,
            min($this->clampLimit($request), (int) config('log-explorer.search.max_results', 1000)),
        );

        try {
            return response()->json($search->search($file, $criteria));
        } catch (SearchFailedException $e) {
            // Usually an invalid regex for the engine's dialect.
            abort(422, $e->getMessage());
        }
    }
}

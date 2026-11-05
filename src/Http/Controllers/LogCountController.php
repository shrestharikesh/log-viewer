<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\LogExplorer\Exceptions\SearchFailedException;
use Vendor\LogExplorer\Http\Controllers\Concerns\BuildsSearchCriteria;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Search\CountResult;
use Vendor\LogExplorer\Search\SearchManager;

/**
 * GET {prefix}/api/count — match counts per time bucket, no lines.
 *
 * Query: file, q, regex, case, level, from, to, cursor,
 *        bucket (minute | hour (default) | day)
 *
 * Answers "when did this start, and how often" without paging through
 * matches. One multi-line record counts once. Bounded by the search timeout;
 * a partial count has meta.complete = false and a per-file cursor.
 */
final class LogCountController
{
    use BuildsSearchCriteria;
    use ResolvesLogFile;

    public function __invoke(Request $request, SearchManager $search): JsonResponse
    {
        $file = $this->resolveFile($request);

        $bucket = (string) $request->query('bucket', 'hour');
        if (! in_array($bucket, CountResult::BUCKETS, true)) {
            abort(422, 'The "bucket" must be one of: '.implode(', ', CountResult::BUCKETS).'.');
        }

        // The limit does not apply to counting; pass the configured cap anyway.
        $criteria = $this->criteriaFrom($request, (int) config('log-explorer.search.max_results', 1000));

        if ($criteria->isBackward() && $criteria->fromOffset > 0) {
            abort(422, 'Count only resumes from its own (forward) cursor, not a newest-first search cursor.');
        }

        try {
            return response()->json($search->count([$file], $criteria, $bucket));
        } catch (SearchFailedException $e) {
            abort(422, $e->getMessage());
        }
    }
}

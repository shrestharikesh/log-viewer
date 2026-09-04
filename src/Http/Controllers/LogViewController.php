<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Reading\FileReader;

/**
 * GET {prefix}/api/view — cursor-paginated read.
 *
 * Query: file, cursor (opaque), direction=forward|previous, limit, parse=1
 */
final class LogViewController
{
    use ResolvesLogFile;

    public function __invoke(Request $request, FileReader $reader): JsonResponse
    {
        $file = $this->resolveFile($request);

        $direction = $request->query('direction') === 'previous' ? 'previous' : 'forward';

        $result = $reader->page(
            file: $file,
            cursor: $request->query('cursor'),
            limit: $this->clampLimit($request),
            direction: $direction,
            parse: $request->boolean('parse', true),
        );

        return response()->json($result);
    }
}

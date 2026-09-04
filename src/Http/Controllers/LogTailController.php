<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Reading\Tailer;

/**
 * GET {prefix}/api/tail — last N lines, read via reverse scan from EOF.
 *
 * Query: file, lines (clamped to tail.presets max / max_page_size), parse=1
 */
final class LogTailController
{
    use ResolvesLogFile;

    public function __invoke(Request $request, Tailer $tailer): JsonResponse
    {
        $file = $this->resolveFile($request);

        $default = (int) config('log-explorer.tail.default', 100);
        $max = (int) config('log-explorer.reading.max_page_size', 5000);
        $lines = max(1, min((int) $request->query('lines', (string) $default), $max));

        $result = $tailer->tail($file, $lines, $request->boolean('parse', true));

        return response()->json($result);
    }
}

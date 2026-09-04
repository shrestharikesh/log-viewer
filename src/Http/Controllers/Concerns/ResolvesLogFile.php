<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Vendor\LogExplorer\Exceptions\InvalidLogFileException;
use Vendor\LogExplorer\Facades\LogExplorer;
use Vendor\LogExplorer\Support\LogFile;

trait ResolvesLogFile
{
    /**
     * Resolve and validate the requested file, aborting with a clean JSON 404
     * (rather than leaking a path) when it is unknown or disallowed.
     */
    protected function resolveFile(Request $request): LogFile
    {
        $identifier = (string) $request->query('file', $request->input('file', ''));

        if ($identifier === '') {
            abort(422, 'A "file" identifier is required.');
        }

        try {
            $driver = $request->query('source');

            return LogExplorer::source($driver ?: null)->resolve($identifier);
        } catch (InvalidLogFileException) {
            abort(404, 'Log file not found.');
        }
    }

    protected function clampLimit(Request $request, string $key = 'limit'): int
    {
        $default = (int) config('log-explorer.reading.page_size', 200);
        $max = (int) config('log-explorer.reading.max_page_size', 5000);

        $limit = (int) $request->query($key, (string) $default);

        return max(1, min($limit, $max));
    }
}

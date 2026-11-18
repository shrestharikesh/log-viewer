<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Vendor\LogExplorer\Auth\Authorizer;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Exceptions\InvalidLogFileException;
use Vendor\LogExplorer\Facades\LogExplorer;
use Vendor\LogExplorer\Http\QueryInput;
use Vendor\LogExplorer\Support\LogFile;

trait ResolvesLogFile
{
    /**
     * Resolve and validate the requested file, aborting with a clean JSON 404
     * (rather than leaking a path) when it is unknown or disallowed, and with
     * a 403 when the view gate/callback refuses this particular file.
     *
     * The route middleware has already checked general view access (with no
     * file); this is the per-file check, so a gate or authorizeUsing()
     * callback receives the LogFile and can allow some files but not others.
     */
    protected function resolveFile(Request $request): LogFile
    {
        $identifier = $request->query('file') ?? $request->input('file');

        if (! is_string($identifier) || $identifier === '') {
            abort(422, 'A "file" identifier is required.');
        }

        try {
            $file = $this->resolveSource($request)->resolve($identifier);
        } catch (InvalidLogFileException) {
            abort(404, 'Log file not found.');
        }

        if (! app(Authorizer::class)->canView($request->user(), $file)) {
            abort(403, 'You are not authorized to view this log file.');
        }

        return $file;
    }

    /**
     * The source named by ?source= (or the default). Driver names are plain
     * identifiers: anything else, or a driver that is not configured, is a
     * 404 rather than an uncaught exception. Rejecting dots also stops the
     * name from addressing nested config keys (e.g. "local.paths").
     */
    protected function resolveSource(Request $request): LogSourceInterface
    {
        $driver = $request->query('source');

        if ($driver === null || $driver === '') {
            return LogExplorer::source();
        }

        if (! is_string($driver) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $driver) !== 1) {
            abort(404, 'Log source not found.');
        }

        try {
            return LogExplorer::source($driver);
        } catch (InvalidArgumentException) {
            abort(404, 'Log source not found.');
        }
    }

    protected function clampLimit(Request $request, string $key = 'limit'): int
    {
        $default = (int) config('log-explorer.reading.page_size', 200);
        $max = (int) config('log-explorer.reading.max_page_size', 5000);

        $limit = (int) QueryInput::string($request, $key, (string) $default);

        return max(1, min($limit, $max));
    }
}

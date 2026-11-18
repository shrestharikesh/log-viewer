<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\LogExplorer\Auth\Authorizer;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Support\LogFile;

/**
 * GET {prefix}/api/files       — list discoverable log files
 * GET {prefix}/api/files/show  — metadata for one file
 */
final class LogFileController
{
    use ResolvesLogFile;

    public function index(Request $request, Authorizer $authorizer): JsonResponse
    {
        $source = $this->resolveSource($request);
        $user = $request->user();

        // Only the files this user may view, per file, so a gate or
        // authorizeUsing() callback can hide some logs entirely.
        $visible = array_values(array_filter(
            $source->files(),
            static fn (LogFile $file): bool => $authorizer->canView($user, $file),
        ));

        $files = array_map(fn ($file) => [
            'identifier' => $file->identifier,
            'name' => $file->name,
            'relative_path' => $file->relativePath,
            'driver' => $file->driver,
            'size' => $source->size($file),
            'size_human' => $this->human($source->size($file)),
            'last_modified' => $source->lastModified($file),
        ], $visible);

        return response()->json(['data' => $files]);
    }

    public function show(Request $request): JsonResponse
    {
        $file = $this->resolveFile($request);
        $source = $this->resolveSource($request);

        $size = $source->size($file);

        return response()->json([
            'data' => [
                'identifier' => $file->identifier,
                'name' => $file->name,
                'relative_path' => $file->relativePath,
                'size' => $size,
                'size_human' => $this->human($size),
                'last_modified' => $source->lastModified($file),
                'downloadable' => $size <= (int) config('log-explorer.download.max_download_size_mb', 100) * 1024 * 1024,
            ],
        ]);
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2).' '.$units[$i];
    }
}

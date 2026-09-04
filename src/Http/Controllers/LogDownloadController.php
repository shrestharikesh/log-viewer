<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vendor\LogExplorer\Auth\Authorizer;
use Vendor\LogExplorer\Facades\LogExplorer;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Reading\LineScanner;
use Vendor\LogExplorer\Reading\Tailer;
use Vendor\LogExplorer\Support\LogFile;

/**
 * GET {prefix}/api/download — guarded, streamed download.
 *
 * Protections, all enforced server-side:
 *   - separate "download" gate (distinct from "view")
 *   - hard size cap (max_download_size_mb) — blocked outright
 *   - soft warning (warn_download_size_mb) — requires ?confirm=1
 *   - per-user rate limiting
 *   - audit log of every attempt (allowed or denied)
 *   - "tail download" escape hatch (?mode=tail&lines=N) so huge files can
 *     still yield their recent entries without transferring 100 GB
 *
 * The file is streamed in fixed-size chunks via fread() — never loaded whole.
 */
final class LogDownloadController
{
    use ResolvesLogFile;

    public function __invoke(Request $request, Authorizer $authorizer, Tailer $tailer): StreamedResponse
    {
        if (! config('log-explorer.download.enabled', true)) {
            abort(403, 'Downloads are disabled.');
        }

        $file = $this->resolveFile($request);
        $user = $request->user();

        // Distinct download authorization.
        if (! $authorizer->canDownload($user, $file)) {
            $this->audit('denied:unauthorized', $request, $file);
            abort(403, 'You are not authorized to download logs.');
        }

        // Rate limiting per user.
        [$attempts, $decay] = $this->rateLimit();
        $key = 'log-explorer:download:'.($user?->getAuthIdentifier() ?? $request->ip());
        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $this->audit('denied:rate_limited', $request, $file);
            abort(429, 'Too many download attempts. Try again in '.RateLimiter::availableIn($key).'s.');
        }
        RateLimiter::hit($key, $decay * 60);

        $source = LogExplorer::source($request->query('source') ?: null);
        $size = $source->size($file);
        $mode = $request->query('mode');

        // Escape hatch: download only the tail of an otherwise-too-large file.
        if ($mode === 'tail') {
            $this->audit('allowed:tail', $request, $file);

            return $this->streamTail($tailer, $file, $request);
        }

        $maxBytes = (int) config('log-explorer.download.max_download_size_mb', 100) * 1024 * 1024;
        $warnBytes = (int) config('log-explorer.download.warn_download_size_mb', 25) * 1024 * 1024;

        if ($size > $maxBytes) {
            $this->audit('denied:too_large', $request, $file);
            abort(413, sprintf(
                'File is %s; the download limit is %d MB. Use ?mode=tail&lines=N to fetch recent entries instead.',
                $this->human($size),
                (int) config('log-explorer.download.max_download_size_mb', 100),
            ));
        }

        if ($size > $warnBytes && ! $request->boolean('confirm')) {
            $this->audit('warned', $request, $file);
            abort(409, sprintf(
                'File is %s. Re-request with ?confirm=1 to download.',
                $this->human($size),
            ));
        }

        $this->audit('allowed:full', $request, $file);

        return $this->streamFull($source->reader($file), $file, $size);
    }

    private function streamFull($stream, LogFile $file, int $size): StreamedResponse
    {
        $chunk = (int) config('log-explorer.reading.chunk_size', 8192) * 8;

        return new StreamedResponse(function () use ($stream, $chunk): void {
            try {
                while (! feof($stream)) {
                    echo fread($stream, $chunk);
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, 200, $this->headers($file->name, $size));
    }

    private function streamTail(Tailer $tailer, LogFile $file, Request $request): StreamedResponse
    {
        $lines = max(1, min((int) $request->query('lines', '1000'), (int) config('log-explorer.reading.max_page_size', 5000)));
        $result = $tailer->tail($file, $lines, parse: false);

        return new StreamedResponse(function () use ($result): void {
            foreach ($result->lines as $line) {
                echo $line->raw."\n";
            }
            flush();
        }, 200, $this->headers('tail-'.$file->name));
    }

    /**
     * @return array<string,string>
     */
    private function headers(string $name, ?int $size = null): array
    {
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.addslashes($name).'"',
            'X-Accel-Buffering' => 'no',
        ];

        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return $headers;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function rateLimit(): array
    {
        $parts = explode(',', (string) config('log-explorer.download.rate_limit', '10,1'));

        return [max(1, (int) ($parts[0] ?? 10)), max(1, (int) ($parts[1] ?? 1))];
    }

    private function audit(string $outcome, Request $request, LogFile $file): void
    {
        if (! config('log-explorer.download.audit', true)) {
            return;
        }

        $channel = config('log-explorer.download.audit_channel');

        ($channel ? Log::channel($channel) : Log::driver())->info('log-explorer.download', [
            'outcome' => $outcome,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
            'file' => $file->relativePath,
            'identifier' => $file->identifier,
            'user_agent' => $request->userAgent(),
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

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vendor\LogExplorer\Facades\LogExplorer;
use Vendor\LogExplorer\Http\Controllers\Concerns\ResolvesLogFile;
use Vendor\LogExplorer\Streaming\LogStreamer;
use Vendor\LogExplorer\Support\ByteCursor;

/**
 * GET {prefix}/api/stream — Server-Sent Events live tail.
 *
 * The browser opens an EventSource and receives "append" events as the file
 * grows. Connections self-terminate after streaming.max_duration so PHP-FPM
 * workers are never pinned; the client's EventSource auto-reconnects, passing
 * back the last offset via ?cursor so no lines are missed or duplicated.
 */
final class LogStreamController
{
    use ResolvesLogFile;

    public function __invoke(Request $request, LogStreamer $streamer): StreamedResponse
    {
        if (! config('log-explorer.streaming.enabled', true)) {
            abort(403, 'Live streaming is disabled.');
        }

        $file = $this->resolveFile($request);
        $source = LogExplorer::source($request->query('source') ?: null);

        // Start at the given cursor, or at EOF (follow new lines only).
        $fromOffset = ByteCursor::decode($request->query('cursor'))?->offset ?? $source->size($file);

        $response = new StreamedResponse(function () use ($streamer, $file, $fromOffset): void {
            // Disable output buffering / time limit for the long-lived stream.
            @set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            foreach ($streamer->follow(
                file: $file,
                fromOffset: $fromOffset,
                maxDurationSeconds: (int) config('log-explorer.streaming.max_duration', 60),
                pollIntervalMs: (int) config('log-explorer.streaming.poll_interval', 1000),
                maxBytesPerTick: (int) config('log-explorer.streaming.max_bytes_per_tick', 262144),
            ) as $batch) {
                $this->emit($batch['event'], [
                    'offset' => $batch['offset'],
                    'lines' => $batch['lines'],
                ]);

                if (connection_aborted()) {
                    break;
                }
            }

            // Tell the client where to resume on reconnect.
            $this->emit('reconnect', ['offset' => $fromOffset]);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no'); // disable nginx buffering
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
        flush();
    }
}

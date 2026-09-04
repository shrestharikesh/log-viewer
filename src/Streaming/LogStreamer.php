<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Streaming;

use Generator;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Reading\LineScanner;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;

/**
 * "tail -f" over a stream. Polls the file for growth and yields only the bytes
 * appended since the last tick — never re-reading what was already sent.
 *
 * It detects rotation/truncation (file shrank) and resets to offset 0 so the
 * follower keeps working across `php artisan log:clear` or logrotate. Each tick
 * is byte-bounded so a burst of logging can't spike memory.
 *
 * Designed to be driven by a StreamedResponse: the controller iterates the
 * generator and writes an SSE frame per yielded batch.
 */
final class LogStreamer
{
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly LineScanner $scanner,
        private readonly ParserManager $parsers,
    ) {
    }

    /**
     * @return Generator<int, array{event:string, offset:int, lines:LogLine[]}>
     */
    public function follow(
        LogFile $file,
        int $fromOffset,
        int $maxDurationSeconds,
        int $pollIntervalMs,
        int $maxBytesPerTick,
        bool $parse = true,
    ): Generator {
        $offset = max(0, $fromOffset);
        $deadline = microtime(true) + $maxDurationSeconds;

        while (microtime(true) < $deadline) {
            // Connection-aware: stop promptly if the browser went away.
            if (connection_aborted()) {
                return;
            }

            $size = $this->source->size($file);

            if ($size < $offset) {
                // File was rotated or truncated — start over from the top.
                $offset = 0;
                yield ['event' => 'rotated', 'offset' => 0, 'lines' => []];
            }

            if ($size > $offset) {
                $batch = $this->readAppended($file, $offset, $size, $maxBytesPerTick, $parse);
                $offset = $batch['offset'];

                if ($batch['lines'] !== []) {
                    yield ['event' => 'append', 'offset' => $offset, 'lines' => $batch['lines']];
                }

                // If there is still more to read (we hit the byte budget),
                // loop immediately instead of sleeping.
                if ($offset < $size) {
                    continue;
                }
            } else {
                // Heartbeat keeps proxies from killing an idle connection.
                yield ['event' => 'heartbeat', 'offset' => $offset, 'lines' => []];
            }

            usleep($pollIntervalMs * 1000);
        }
    }

    /**
     * @return array{offset:int, lines:LogLine[]}
     */
    private function readAppended(LogFile $file, int $offset, int $size, int $maxBytes, bool $parse): array
    {
        $stream = $this->source->reader($file);

        try {
            fseek($stream, $offset, SEEK_SET);
            $lines = [];
            $budget = $maxBytes;

            while ($offset < $size && $budget > 0) {
                $line = $this->scanner->readLine($stream);
                if ($line === null) {
                    break;
                }
                $end = $offset + $line['consumed'];
                $lines[] = new LogLine($offset, $end, $line['content'], $line['truncated']);
                $budget -= $line['consumed'];
                $offset = $end;
            }

            if ($parse) {
                $lines = $this->parsers->parseLines($lines);
            }

            return ['offset' => $offset, 'lines' => $lines];
        } finally {
            fclose($stream);
        }
    }
}

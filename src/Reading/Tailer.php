<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Reading;

use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\PageResult;

/**
 * Efficient "last N lines" reading.
 *
 * Unlike a naive tail that scans the whole file, this seeks to EOF and walks
 * backwards in chunks only far enough to collect N newlines, then reads forward
 * from that boundary. Cost is O(bytes in the last N lines), independent of file
 * size — tailing a 100 GB file touches only a few KB.
 */
final class Tailer
{
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly LineScanner $scanner,
        private readonly ParserManager $parsers,
    ) {
    }

    public function tail(LogFile $file, int $lines, bool $parse = true): PageResult
    {
        $size = $this->source->size($file);
        $mtime = $this->source->lastModified($file);
        $fingerprint = ByteCursor::fingerprint($size, $mtime);

        if ($size === 0) {
            return new PageResult([], null, null, 0, true, true);
        }

        $stream = $this->source->reader($file);

        try {
            $start = $this->scanner->findTailOffset($stream, $size, $lines);
            $read = $this->scanner->readForward($stream, $start, $lines, $size);

            $emitted = $parse ? $this->parsers->parseLines($read['lines']) : $read['lines'];
            $firstOffset = $emitted === [] ? $start : $emitted[0]->offset;

            return new PageResult(
                lines: $emitted,
                // Following the tail means reading forward from EOF as bytes
                // are appended — that is what LogStreamer consumes.
                nextCursor: (new ByteCursor($size, $fingerprint))->encode(),
                prevCursor: $firstOffset > 0 ? (new ByteCursor($firstOffset, $fingerprint))->encode() : null,
                fileSize: $size,
                reachedStart: $firstOffset <= 0,
                reachedEnd: true,
            );
        } finally {
            fclose($stream);
        }
    }
}

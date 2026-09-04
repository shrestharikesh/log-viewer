<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Reading;

use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\LogLine;
use Vendor\LogExplorer\Support\PageResult;

/**
 * Cursor-paginated, streamed reads over a single log file.
 *
 * Pagination is by BYTE OFFSET, never line number, so jumping to any page is an
 * O(1) fseek() rather than an O(file) scan. Forward paging just seeks and reads
 * N lines; backward paging first walks back N lines to find the start offset,
 * then reads forward from there (so emitted lines are always in file order).
 */
final class FileReader
{
    public function __construct(
        private readonly LogSourceInterface $source,
        private readonly LineScanner $scanner,
        private readonly ParserManager $parsers,
    ) {
    }

    /**
     * @param  'forward'|'previous'  $direction
     */
    public function page(LogFile $file, ?string $cursor, int $limit, string $direction = 'forward', bool $parse = true): PageResult
    {
        $size = $this->source->size($file);
        $mtime = $this->source->lastModified($file);
        $fingerprint = ByteCursor::fingerprint($size, $mtime);

        $decoded = ByteCursor::decode($cursor);

        // Stale cursor against a rotated/truncated file → reset to a sane edge.
        if ($decoded !== null && ! $decoded->validFor($size, $mtime)) {
            $decoded = null;
        }

        $stream = $this->source->reader($file);

        try {
            if ($direction === 'previous') {
                // The cursor marks the START of the currently visible page; we
                // want the $limit lines that precede it.
                $end = $decoded?->offset ?? $size;
                $start = $this->scanner->findOffsetBefore($stream, $end, $limit);
                $read = $this->scanner->readForward($stream, $start, $limit, $size);
            } else {
                $start = $decoded?->offset ?? 0;
                $read = $this->scanner->readForward($stream, $start, $limit, $size);
            }

            $lines = $this->maybeParse($read['lines'], $parse);

            $firstOffset = $lines === [] ? $start : $lines[0]->offset;
            $endOffset = $read['endOffset'];

            $reachedStart = $firstOffset <= 0;
            $reachedEnd = $read['reachedEnd'];

            return new PageResult(
                lines: $lines,
                nextCursor: $reachedEnd ? null : (new ByteCursor($endOffset, $fingerprint))->encode(),
                prevCursor: $reachedStart ? null : (new ByteCursor($firstOffset, $fingerprint))->encode(),
                fileSize: $size,
                reachedStart: $reachedStart,
                reachedEnd: $reachedEnd,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  LogLine[]  $lines
     * @return LogLine[]
     */
    private function maybeParse(array $lines, bool $parse): array
    {
        if (! $parse) {
            return $lines;
        }

        return $this->parsers->parseLines($lines);
    }
}

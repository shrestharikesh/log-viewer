<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Reading;

use Vendor\LogExplorer\Support\LogLine;

/**
 * Low-level, memory-bounded line scanning over a seekable stream.
 *
 * Every method here works in fixed-size chunks via fseek()/fread()/fgets() and
 * NEVER materialises more than a few chunks at once. This is what lets the
 * package page through a 100 GB file inside a ~few-MB footprint:
 *
 *   - readForward(): seek to a byte offset and emit up to N lines forward.
 *   - findOffsetBefore(): walk backwards to locate the line boundary N lines
 *     above a given offset — the basis of "previous page" and tail.
 *   - readLine(): read ONE line with a hard length cap so a pathological
 *     newline-free file can never blow the heap.
 *
 * Byte-offset invariant: every offset this class returns is a line boundary
 * (byte 0, or the byte immediately after a "\n"). Cursors rely on this.
 */
final class LineScanner
{
    public function __construct(
        private readonly int $chunkSize = 8192,
        private readonly int $maxLineLength = 32768,
    ) {
    }

    /**
     * Read a single line starting at the stream's current position, capping the
     * in-memory content at maxLineLength. The full byte length consumed (incl.
     * the trailing newline) is always reported so offsets stay exact even when
     * the visible content was truncated.
     *
     * @return array{content:string, consumed:int, truncated:bool}|null  null at EOF
     */
    public function readLine($stream): ?array
    {
        // fgets stops at a newline OR after (length-1) bytes, so this single
        // call buffers at most maxLineLength bytes regardless of line size.
        $first = fgets($stream, $this->maxLineLength + 1);
        if ($first === false) {
            return null; // EOF
        }

        $consumed = strlen($first);
        $truncated = false;

        // If we stopped without a newline the line is either over-long or the
        // final unterminated line. Drain the remainder in bounded chunks so we
        // advance the offset correctly without ever buffering the whole line.
        if (! str_ends_with($first, "\n")) {
            while (! feof($stream)) {
                $skip = fgets($stream, $this->chunkSize + 1);
                if ($skip === false) {
                    break;
                }
                $consumed += strlen($skip);
                $truncated = true;
                if (str_ends_with($skip, "\n")) {
                    break;
                }
            }
        }

        return [
            'content' => rtrim($first, "\r\n"),
            'consumed' => $consumed,
            'truncated' => $truncated,
        ];
    }

    /**
     * Emit up to $limit lines reading forward from $startOffset.
     *
     * @return array{lines: LogLine[], endOffset:int, reachedEnd:bool}
     */
    public function readForward($stream, int $startOffset, int $limit, int $size): array
    {
        fseek($stream, $startOffset, SEEK_SET);

        $offset = $startOffset;
        $lines = [];

        while (count($lines) < $limit && $offset < $size) {
            $line = $this->readLine($stream);
            if ($line === null) {
                break;
            }

            $end = $offset + $line['consumed'];
            $lines[] = new LogLine($offset, $end, $line['content'], $line['truncated']);
            $offset = $end;
        }

        return [
            'lines' => $lines,
            'endOffset' => $offset,
            'reachedEnd' => $offset >= $size,
        ];
    }

    /**
     * Locate the line boundary that is $lines lines above $endOffset by scanning
     * backwards in chunks. $endOffset MUST be a line boundary.
     *
     * Counts newlines in [S, endOffset); since the region ends on a boundary,
     * the line count there equals the newline count. We therefore stop after
     * the ($lines + 1)-th newline from the end and return the position just
     * after it. Returns 0 when the file has fewer than $lines lines above.
     */
    public function findOffsetBefore($stream, int $endOffset, int $lines): int
    {
        if ($endOffset <= 0 || $lines <= 0) {
            return 0;
        }

        $pos = $endOffset;
        $needed = $lines + 1;
        $seen = 0;

        while ($pos > 0) {
            $read = min($this->chunkSize, $pos);
            $pos -= $read;
            fseek($stream, $pos, SEEK_SET);
            $chunk = fread($stream, $read);

            for ($i = strlen($chunk) - 1; $i >= 0; $i--) {
                if ($chunk[$i] !== "\n") {
                    continue;
                }
                if (++$seen === $needed) {
                    return $pos + $i + 1;
                }
            }
        }

        return 0;
    }

    /**
     * Like findOffsetBefore() but anchored at EOF, correcting for whether the
     * file ends with a newline. Returns the byte offset where reading forward
     * yields the last $lines lines of the file.
     */
    public function findTailOffset($stream, int $size, int $lines): int
    {
        if ($size <= 0 || $lines <= 0) {
            return 0;
        }

        // A file ending in "\n" has a "phantom" boundary at EOF; without it,
        // the final unterminated line shifts the newline count by one.
        fseek($stream, $size - 1, SEEK_SET);
        $trailingNewline = fread($stream, 1) === "\n";
        $needed = $trailingNewline ? $lines + 1 : $lines;

        $pos = $size;
        $seen = 0;

        while ($pos > 0) {
            $read = min($this->chunkSize, $pos);
            $pos -= $read;
            fseek($stream, $pos, SEEK_SET);
            $chunk = fread($stream, $read);

            for ($i = strlen($chunk) - 1; $i >= 0; $i--) {
                if ($chunk[$i] !== "\n") {
                    continue;
                }
                if (++$seen === $needed) {
                    return $pos + $i + 1;
                }
            }
        }

        return 0;
    }
}

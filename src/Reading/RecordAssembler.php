<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Reading;

use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Support\LogLine;

/**
 * Reconstructs one complete multi-line log record (a header plus its
 * continuation lines — stack traces, pretty-printed context) from a stream.
 *
 * A Laravel/Monolog record is a LOGICAL unit that can span many PHYSICAL
 * lines, but `ParserManager::parseLines()` — the thing that actually knows
 * how to stitch continuation lines onto a header — only does so within a
 * single batch passed to one call. This class is the piece that finds the
 * right batch: `foldFrom()` reads forward from a known header offset to the
 * next header/EOF/cap; `readRecordAt()` additionally walks backward first,
 * for callers (grep/ripgrep search hits) that land on an arbitrary line
 * somewhere inside a record rather than at its header.
 */
final class RecordAssembler
{
    public function __construct(
        private readonly LineScanner $scanner,
        private readonly ParserManager $parsers,
        private readonly int $maxContinuationLines = 200,
    ) {
    }

    /**
     * Fold the record that STARTS at $offset. Does no backward search: if the
     * line at $offset isn't actually a recognised header (e.g. a resume
     * cursor landing mid-record, or the tail of a record whose continuation
     * count hit the cap on a previous read), it is treated as the start of a
     * new orphan record — same fallback `parseLines()` already uses. This
     * always advances at least one line, so a caller looping via `endOffset`
     * can never stall.
     *
     * @param  resource  $stream  seekable; this seeks it itself
     */
    public function foldFrom($stream, int $offset, int $size): ?LogLine
    {
        if ($offset >= $size) {
            return null;
        }

        fseek($stream, $offset, SEEK_SET);
        $first = $this->scanner->readLine($stream);
        if ($first === null) {
            return null;
        }

        $end = $offset + $first['consumed'];
        $batch = [new LogLine($offset, $end, $first['content'], $first['truncated'])];

        $folded = 0;
        while ($end < $size && $folded < $this->maxContinuationLines) {
            fseek($stream, $end, SEEK_SET);
            $line = $this->scanner->readLine($stream);
            if ($line === null) {
                break;
            }

            // The next record's header — leave it for the next call.
            if ($this->parsers->first($line['content']) !== null) {
                break;
            }

            $lineEnd = $end + $line['consumed'];
            $batch[] = new LogLine($end, $lineEnd, $line['content'], $line['truncated']);
            $end = $lineEnd;
            $folded++;
        }

        return $this->parsers->parseLines($batch)[0];
    }

    /**
     * Fold the record that CONTAINS $lineOffset, which may be its header or
     * any of its continuation lines. Walks backward one line at a time
     * (bounded by maxContinuationLines) to find the enclosing header, then
     * folds forward from there. Falls back to treating $lineOffset itself as
     * the record start if no header is found within the budget.
     *
     * @param  resource  $stream  seekable; this seeks it itself
     */
    public function readRecordAt($stream, int $lineOffset, int $size): ?LogLine
    {
        return $this->foldFrom($stream, $this->locateHeaderOffset($stream, $lineOffset, $size), $size);
    }

    private function locateHeaderOffset($stream, int $lineOffset, int $size): int
    {
        if ($lineOffset >= $size) {
            return $lineOffset;
        }

        fseek($stream, $lineOffset, SEEK_SET);
        $line = $this->scanner->readLine($stream);

        if ($line === null || $this->parsers->first($line['content']) !== null) {
            return $lineOffset;
        }

        for ($back = 1; $back <= $this->maxContinuationLines; $back++) {
            $candidate = $this->scanner->findOffsetBefore($stream, $lineOffset, $back);

            fseek($stream, $candidate, SEEK_SET);
            $probe = $this->scanner->readLine($stream);

            if ($probe !== null && $this->parsers->first($probe['content']) !== null) {
                return $candidate;
            }

            if ($candidate === 0) {
                break;
            }
        }

        // No header within the lookback budget — treat as an orphan; the
        // caller ends up with the same behaviour as before this class existed.
        return $lineOffset;
    }
}

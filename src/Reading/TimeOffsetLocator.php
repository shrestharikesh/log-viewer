<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Reading;

use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Support\Timestamp;

/**
 * Maps a point in time to a byte offset in an append-only, time-ordered log
 * by binary search, so "errors since 14:00" starts reading at 14:00 instead
 * of byte 0. About 40 probes for a 100 GB file; each probe reads forward from
 * a byte position only as far as the next timestamped record header.
 *
 * SAFETY CONTRACT: the offsets returned may describe a WIDER range than the
 * exact answer, never a narrower one, so callers must still apply the exact
 * timestamp filter to each record:
 *
 *   - offsetFor() errs EARLY. A probe that cannot decide (no timestamped
 *     header within the probe window) moves the search left.
 *   - endOffsetFor() errs LATE. An undecided probe moves the search right.
 *   - A file with no timestamped header in its first probe window (plain
 *     text, JSON without a time field, binary) is treated as unordered and
 *     gets the full range: 0 and $size.
 *
 * The one assumption is ordering: timestamps must be non-decreasing through
 * the file, give or take the caller's clock-skew margin. Lines out of order
 * by more than that margin can be missed near the boundary.
 *
 * Every offset returned is a record header, 0, or $size, so a range built
 * from them never splits a record.
 */
final class TimeOffsetLocator
{
    public function __construct(
        private readonly LineScanner $scanner,
        private readonly ParserManager $parsers,
        private readonly int $probeBytes = 1048576,
    ) {
    }

    /**
     * Offset of the first record whose timestamp is >= $timestamp — the
     * inclusive start of a "since" range. 0 when undecidable; $size when
     * every record is older.
     *
     * @param  resource  $stream  seekable; this seeks it itself
     */
    public function offsetFor($stream, int $size, int $timestamp): int
    {
        if ($size <= 0) {
            return 0;
        }

        $first = $this->probe($stream, 0, $size);
        if ($first['ts'] === null || $first['ts'] >= $timestamp) {
            return 0;
        }

        // Invariant: every timestamped header before $lo is older than the
        // target. $floor is the last such header, the fallback start if the
        // final alignment probe finds no header at all.
        $lo = $first['at'] + 1;
        $hi = $size;
        $floor = $first['at'];

        while ($lo < $hi) {
            $mid = $lo + intdiv($hi - $lo, 2);
            $probe = $this->probe($stream, $mid, $size);

            if ($probe['ts'] !== null && $probe['ts'] < $timestamp) {
                $floor = $probe['at'];
                $lo = $probe['at'] + 1;
            } else {
                // At/after the target, or undecidable: go left (safe side).
                $hi = $mid;
            }
        }

        if ($lo >= $size) {
            return $size;
        }

        // Align to the first record header at or after $lo (timestamped or
        // not), so records with no timestamp there are not skipped. No header
        // before EOF means every record is older; no header within the probe
        // window means "can't tell", so fall back to the last older header.
        $align = $this->probe($stream, $lo, $size);

        return $align['header'] ?? ($align['eof'] ? $size : $floor);
    }

    /**
     * Offset of the first record whose timestamp is > $timestamp — the
     * EXCLUSIVE end of an "until" range. $size when undecidable or when no
     * record is newer.
     *
     * @param  resource  $stream  seekable; this seeks it itself
     */
    public function endOffsetFor($stream, int $size, int $timestamp): int
    {
        if ($size <= 0) {
            return 0;
        }

        if ($this->probe($stream, 0, $size)['ts'] === null) {
            return $size;
        }

        // Invariant: every timestamped header before $lo is <= the target;
        // $ceil is the first header known to be newer.
        $lo = 0;
        $hi = $size;
        $ceil = $size;

        while ($lo < $hi) {
            $mid = $lo + intdiv($hi - $lo, 2);
            $probe = $this->probe($stream, $mid, $size);

            if ($probe['ts'] === null) {
                // Undecidable: go right (safe side).
                $lo = $mid + 1;
            } elseif ($probe['ts'] <= $timestamp) {
                $lo = $probe['at'] + 1;
            } else {
                $ceil = min($ceil, $probe['at']);
                $hi = $mid;
            }
        }

        return $ceil;
    }

    /**
     * Read forward from the first line boundary at/after $from until a record
     * header with a parseable timestamp, giving up after probeBytes.
     *
     * @return array{header:?int, at:?int, ts:?int, eof:bool}
     *   header: first record header seen (timestamped or not)
     *   at/ts:  offset and timestamp of the first TIMESTAMPED header
     *   eof:    the probe ran to the end of the file (rather than out of window)
     */
    private function probe($stream, int $from, int $size): array
    {
        $pos = $from;

        // Skip the partial line $from lands in. Reading from $from - 1 makes
        // a $from that is already a boundary cost one byte, not one line.
        if ($pos > 0) {
            fseek($stream, $pos - 1, SEEK_SET);
            $partial = $this->scanner->readLine($stream);
            $pos = $partial === null ? $size : $pos - 1 + $partial['consumed'];
        } else {
            fseek($stream, 0, SEEK_SET);
        }

        $header = null;
        $limit = $pos + $this->probeBytes;

        while ($pos < $size && $pos < $limit) {
            $line = $this->scanner->readLine($stream);
            if ($line === null) {
                break;
            }

            $parser = $this->parsers->first($line['content']);
            if ($parser !== null) {
                $header ??= $pos;

                $ts = Timestamp::parse($parser->parse($line['content'])->datetime);
                if ($ts !== null) {
                    return ['header' => $header, 'at' => $pos, 'ts' => $ts, 'eof' => false];
                }
            }

            $pos += $line['consumed'];
        }

        return ['header' => $header, 'at' => null, 'ts' => null, 'eof' => $pos >= $size];
    }
}

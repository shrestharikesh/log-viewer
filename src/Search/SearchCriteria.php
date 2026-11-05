<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use InvalidArgumentException;
use Vendor\LogExplorer\Support\LogLine;
use Vendor\LogExplorer\Support\Timestamp;

/**
 * Normalised search parameters shared by every searcher backend.
 *
 * `fromOffset` makes search itself cursor-paginated: a searcher resumes from a
 * byte offset and returns the offset to continue from, so "load more results"
 * never re-scans matched bytes.
 *
 * `endOffset` (exclusive) bounds the scan from above; records whose header
 * starts at or after it are not visited. It should be a record boundary (a
 * cursor or a record's offset); null means "to the end of the file".
 *
 * `direction` is 'forward' (oldest first, the default) or 'backward' (newest
 * first). In backward mode `fromOffset` is the exclusive UPPER bound to resume
 * below (0 = start from the end of the file), which is what a backward
 * cursor encodes.
 */
final class SearchCriteria
{
    public const FORWARD = 'forward';

    public const BACKWARD = 'backward';

    /** @var array{0:?int,1:?int}|null  memoised [from, to] unix timestamps */
    private ?array $timeBounds = null;

    public function __construct(
        public readonly string $query,
        public readonly bool $regex = false,
        public readonly bool $caseSensitive = false,
        public readonly ?string $level = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly int $limit = 1000,
        public readonly int $fromOffset = 0,
        public readonly int $timeout = 30,
        public readonly ?int $endOffset = null,
        public readonly string $direction = self::FORWARD,
    ) {
        if ($direction !== self::FORWARD && $direction !== self::BACKWARD) {
            throw new InvalidArgumentException("Search direction must be 'forward' or 'backward', got '{$direction}'.");
        }
    }

    public function hasTextQuery(): bool
    {
        return trim($this->query) !== '';
    }

    public function isBackward(): bool
    {
        return $this->direction === self::BACKWARD;
    }

    /**
     * `dateFrom` / `dateTo` as unix timestamps, or null when absent or
     * unparseable (an unparseable bound is ignored rather than matching
     * nothing).
     *
     * @return array{0:?int,1:?int}
     */
    public function timeBounds(): array
    {
        return $this->timeBounds ??= [
            Timestamp::parse($this->dateFrom),
            Timestamp::parse($this->dateTo),
        ];
    }

    public function hasTimeBounds(): bool
    {
        [$from, $to] = $this->timeBounds();

        return $from !== null || $to !== null;
    }

    /**
     * The exact level/date post-filter every engine applies to a reassembled
     * record. A record with no parseable timestamp passes the date filter (it
     * can't be placed in time, so it isn't excluded).
     */
    public function passesStructuredFilters(LogLine $line): bool
    {
        $parsed = $line->parsed;

        if ($this->level !== null && $this->level !== '') {
            if ($parsed?->level === null || strcasecmp($parsed->level, $this->level) !== 0) {
                return false;
            }
        }

        [$from, $to] = $this->timeBounds();
        if (($from !== null || $to !== null) && $parsed?->datetime !== null) {
            $ts = Timestamp::parse($parsed->datetime);
            if ($ts !== null) {
                if ($from !== null && $ts < $from) {
                    return false;
                }
                if ($to !== null && $ts > $to) {
                    return false;
                }
            }
        }

        return true;
    }
}

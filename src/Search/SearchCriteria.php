<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

/**
 * Normalised search parameters shared by every searcher backend.
 *
 * `fromOffset` makes search itself cursor-paginated: a searcher resumes from a
 * byte offset and returns the offset to continue from, so "load more results"
 * never re-scans matched bytes.
 */
final class SearchCriteria
{
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
    ) {
    }

    public function hasTextQuery(): bool
    {
        return trim($this->query) !== '';
    }
}

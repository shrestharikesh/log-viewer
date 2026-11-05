<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Support\LogLine;

final class SearchResult implements \JsonSerializable
{
    /**
     * @param  LogLine[]  $matches  in the order of `direction` (newest first when backward)
     * @param  array{start:int,end:int}|null  $range  the byte range this search covered
     *   after the time seek and cursor were applied; null when not scanned
     */
    public function __construct(
        public readonly array $matches,
        public readonly ?string $nextCursor,
        public readonly bool $complete,
        public readonly string $engine,
        public readonly bool $truncated = false,
        public readonly ?array $range = null,
        public readonly string $direction = SearchCriteria::FORWARD,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'matches' => $this->matches,
            'cursor' => ['next' => $this->nextCursor],
            'meta' => [
                'engine' => $this->engine,
                'complete' => $this->complete,
                'truncated' => $this->truncated,
                'count' => count($this->matches),
                'direction' => $this->direction,
                'range' => $this->range,
            ],
        ];
    }
}

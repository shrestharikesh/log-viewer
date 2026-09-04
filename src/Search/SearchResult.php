<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Support\LogLine;

final class SearchResult implements \JsonSerializable
{
    /**
     * @param  LogLine[]  $matches
     */
    public function __construct(
        public readonly array $matches,
        public readonly ?string $nextCursor,
        public readonly bool $complete,
        public readonly string $engine,
        public readonly bool $truncated = false,
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
            ],
        ];
    }
}

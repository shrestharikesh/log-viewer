<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Support;

/**
 * Result of a paginated read. Cursors are opaque (base64 byte offsets).
 *
 * - `nextCursor`: byte offset to read forward from for the following page,
 *   or null when EOF was reached.
 * - `prevCursor`: byte offset of the start of the line BEFORE this page, or
 *   null when the start of file was reached.
 */
final class PageResult implements \JsonSerializable
{
    /**
     * @param  LogLine[]  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?string $nextCursor,
        public readonly ?string $prevCursor,
        public readonly int $fileSize,
        public readonly bool $reachedStart,
        public readonly bool $reachedEnd,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'lines' => $this->lines,
            'cursor' => [
                'next' => $this->nextCursor,
                'previous' => $this->prevCursor,
            ],
            'meta' => [
                'file_size' => $this->fileSize,
                'reached_start' => $this->reachedStart,
                'reached_end' => $this->reachedEnd,
                'count' => count($this->lines),
            ],
        ];
    }
}

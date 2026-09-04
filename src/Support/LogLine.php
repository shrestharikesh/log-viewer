<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Support;

use Vendor\LogExplorer\Parsing\ParsedLine;

/**
 * One emitted line: its raw text, the byte range it occupied in the file, and
 * (lazily) its parsed structure. `offset` is the byte position of the line's
 * first character; `endOffset` is the position immediately after its trailing
 * newline — i.e. the start of the next line, used as the forward cursor.
 */
final class LogLine implements \JsonSerializable
{
    public function __construct(
        public readonly int $offset,
        public readonly int $endOffset,
        public readonly string $raw,
        public readonly bool $truncated = false,
        public readonly ?ParsedLine $parsed = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'offset' => $this->offset,
            'end_offset' => $this->endOffset,
            'raw' => $this->raw,
            'truncated' => $this->truncated,
            'parsed' => $this->parsed,
        ];
    }
}

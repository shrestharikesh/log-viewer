<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

use Vendor\LogExplorer\Support\LogFile;

/**
 * One file's slice of a multi-file search.
 *
 * A batch search shares a single limit/timeout budget across every file, so the
 * matches come back interleaved from one process. This pairs each SearchResult
 * back with the LogFile it belongs to, which is what the UI groups on.
 */
final class FileSearchResult implements \JsonSerializable
{
    public function __construct(
        public readonly LogFile $file,
        public readonly SearchResult $result,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'file' => [
                'identifier' => $this->file->identifier,
                'name' => $this->file->name,
                'relative_path' => $this->file->relativePath,
                'driver' => $this->file->driver,
            ],
        ] + $this->result->jsonSerialize();
    }
}

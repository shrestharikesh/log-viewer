<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Search;

/**
 * Match counts per time bucket, without the matching lines.
 *
 * Counting unit: one multi-line RECORD counts once, however many of its lines
 * match, the same unit search returns. A record with no parseable timestamp
 * is counted in `total` and `undated`, not in a bucket.
 *
 * `bucket` is the granularity actually used. It can be coarser than the one
 * requested: past MAX_BUCKETS distinct buckets, minute buckets are merged
 * into hours and hours into days, to keep memory bounded.
 */
final class CountResult implements \JsonSerializable
{
    public const BUCKETS = ['minute', 'hour', 'day'];

    public const MAX_BUCKETS = 10000;

    /**
     * @param  array<string,int>  $buckets  bucket label (local time, e.g. "2026-01-01 10:00") => count, ascending
     * @param  array<int,array{file:\Vendor\LogExplorer\Support\LogFile,total:int,complete:bool,cursor:?string,range:?array}>  $files
     */
    public function __construct(
        public readonly string $bucket,
        public readonly array $buckets,
        public readonly int $total,
        public readonly int $undated,
        public readonly ?int $first,
        public readonly ?int $last,
        public readonly bool $complete,
        public readonly string $engine,
        public readonly array $files = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'bucket' => $this->bucket,
            'buckets' => $this->buckets === [] ? new \stdClass() : $this->buckets,
            'total' => $this->total,
            'undated' => $this->undated,
            'first' => $this->first === null ? null : date('c', $this->first),
            'last' => $this->last === null ? null : date('c', $this->last),
            'meta' => [
                'engine' => $this->engine,
                'complete' => $this->complete,
            ],
            'files' => array_map(static fn (array $f) => [
                'file' => [
                    'identifier' => $f['file']->identifier,
                    'name' => $f['file']->name,
                ],
                'total' => $f['total'],
                'complete' => $f['complete'],
                'cursor' => ['next' => $f['cursor']],
                'range' => $f['range'],
            ], $this->files),
        ];
    }
}

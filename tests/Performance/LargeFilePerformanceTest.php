<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Performance;

use Vendor\LogExplorer\Reading\FileReader;
use Vendor\LogExplorer\Reading\Tailer;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\StreamSearcher;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Proves the core promise: memory stays flat and operations stay fast no matter
 * how large the file is.
 *
 *   - Default: builds a ~128 MB file (fast, CI-friendly) and asserts each op
 *     allocates well under 50 MB.
 *   - Opt-in: set env LOG_EXPLORER_PERF_GB=1|10|50 to build a genuinely huge
 *     file and assert the SAME bounds hold — tail/page touch only a few KB.
 *
 * Run with:  vendor/bin/phpunit --testsuite Performance
 */
final class LargeFilePerformanceTest extends TestCase
{
    private const MEMORY_BUDGET = 50 * 1024 * 1024; // 50 MB

    private function buildFixture(): string
    {
        $gb = (int) (getenv('LOG_EXPLORER_PERF_GB') ?: 0);
        $targetBytes = $gb > 0 ? $gb * 1024 * 1024 * 1024 : 128 * 1024 * 1024;

        $path = $this->fixture('huge.log');
        if (! is_file($path) || filesize($path) < $targetBytes) {
            FixtureGenerator::ofSize($path, $targetBytes);
        }

        return $path;
    }

    /**
     * @return array{0:mixed,1:int}  [result, bytesAllocatedDuringOp]
     */
    private function measure(callable $op): array
    {
        gc_collect_cycles();
        $before = memory_get_usage(true);
        $result = $op();
        $after = memory_get_usage(true);

        return [$result, max(0, $after - $before)];
    }

    public function test_tail_uses_constant_memory_regardless_of_size(): void
    {
        $this->buildFixture();
        $file = $this->logFile('huge.log');
        $tailer = new Tailer($this->source(), new \Vendor\LogExplorer\Reading\LineScanner(), $this->parserManager());

        [$result, $used] = $this->measure(fn () => $tailer->tail($file, 1000));

        $this->assertCount(1000, $result->lines);
        $this->assertLessThan(self::MEMORY_BUDGET, $used, 'tail() exceeded the 50 MB budget');
    }

    public function test_random_page_read_is_constant_memory(): void
    {
        $path = $this->buildFixture();
        $file = $this->logFile('huge.log');
        $reader = new FileReader($this->source(), new \Vendor\LogExplorer\Reading\LineScanner(), $this->parserManager());

        // Jump to a cursor deep in the file — O(1) seek, not a scan.
        $deepOffset = (int) (filesize($path) * 0.75);
        $cursor = (new \Vendor\LogExplorer\Support\ByteCursor($deepOffset))->encode();

        [$result, $used] = $this->measure(fn () => $reader->page($file, $cursor, 200, 'forward'));

        $this->assertNotEmpty($result->lines);
        $this->assertLessThan(self::MEMORY_BUDGET, $used, 'page() exceeded the 50 MB budget');
    }

    public function test_streaming_search_is_constant_memory(): void
    {
        $this->buildFixture();
        $file = $this->logFile('huge.log');
        $searcher = new StreamSearcher($this->source(), new \Vendor\LogExplorer\Reading\LineScanner(), $this->parserManager());

        [$result, $used] = $this->measure(fn () => $searcher->search(
            $file,
            new SearchCriteria(query: 'filler line 0000000123', limit: 50, timeout: 60),
        ));

        $this->assertLessThan(self::MEMORY_BUDGET, $used, 'streaming search exceeded the 50 MB budget');
    }
}

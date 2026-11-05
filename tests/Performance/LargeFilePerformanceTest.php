<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Performance;

use Vendor\LogExplorer\Reading\FileReader;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Reading\Tailer;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\StreamSearcher;
use Vendor\LogExplorer\Support\ByteCursor;
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
 *   - Time-window and newest-first search must cost the window, not the
 *     file: asserted on the byte range each engine was handed, which is
 *     independent of the file size.
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
        $records = new RecordAssembler(new \Vendor\LogExplorer\Reading\LineScanner(), $this->parserManager());
        $searcher = new StreamSearcher($this->source(), $records);

        [$result, $used] = $this->measure(fn () => $searcher->search(
            $file,
            new SearchCriteria(query: 'filler line 0000000123', limit: 50, timeout: 60),
        ));

        $this->assertLessThan(self::MEMORY_BUDGET, $used, 'streaming search exceeded the 50 MB budget');
    }

    /* ------------------------------------------------------------------ */
    /* Time-window seek and newest-first search                            */
    /* ------------------------------------------------------------------ */

    /** Bytes the last hour of the timed fixture can span, plus the 300 s margin. */
    private const LAST_HOUR_BUDGET = 8 * 1024 * 1024;

    /**
     * A time-ordered fixture: 10 records per second, every 10th an ERROR,
     * so exactly one ERROR per second.
     *
     * @return array{0:int,1:int}  [fileSize, lastTimestamp]
     */
    private function buildTimedFixture(): array
    {
        $gb = (int) (getenv('LOG_EXPLORER_PERF_GB') ?: 0);
        $targetBytes = $gb > 0 ? $gb * 1024 * 1024 * 1024 : 128 * 1024 * 1024;

        [$bytes, , $last] = FixtureGenerator::timedOfSize(
            $this->fixture('timed.log'),
            $targetBytes,
            (int) strtotime('2026-01-01 00:00:00'),
        );

        return [$bytes, $last];
    }

    public function test_last_hour_search_is_bounded_by_the_window_not_the_file(): void
    {
        [$size, $last] = $this->buildTimedFixture();
        $file = $this->logFile('timed.log');

        foreach ($this->availableEngines() as $engine) {
            $started = microtime(true);
            [$result, $used] = $this->measure(fn () => $this->searchManager($engine)->search($file, new SearchCriteria(
                query: 'testing.ERROR',
                dateFrom: date('Y-m-d H:i:s', $last - 3600),
                limit: 10000,
                timeout: 60,
            )));
            $elapsed = microtime(true) - $started;

            $this->assertTrue($result->complete, "{$engine}: last-hour search did not complete");
            $this->assertCount(3601, $result->matches, $engine);
            $this->assertLessThan(
                self::LAST_HOUR_BUDGET,
                $result->range['end'] - $result->range['start'],
                "{$engine}: scanned range is not bounded by the time window",
            );
            $this->assertSame($size, $result->range['end'], $engine);
            $this->assertLessThan(self::MEMORY_BUDGET, $used, "{$engine}: last-hour search exceeded the 50 MB budget");

            fwrite(STDERR, sprintf(
                "\n  [%s] last hour of %d MB: %.3fs, scanned %.2f MB\n",
                $engine, intdiv($size, 1048576), $elapsed, ($result->range['end'] - $result->range['start']) / 1048576,
            ));
        }
    }

    public function test_newest_first_page_costs_one_window(): void
    {
        [$size, $last] = $this->buildTimedFixture();
        $file = $this->logFile('timed.log');
        $window = 4 * 1024 * 1024;

        foreach ($this->availableEngines() as $engine) {
            $result = $this->searchManager($engine, ['backward_window' => $window])->search($file, new SearchCriteria(
                query: 'testing.ERROR',
                limit: 50,
                timeout: 60,
                direction: SearchCriteria::BACKWARD,
            ));

            $this->assertCount(50, $result->matches, $engine);
            $this->assertStringStartsWith('['.date('Y-m-d H:i:s', $last).']', $result->matches[0]->raw, $engine);

            // Resumes inside the last window: nothing older was read.
            $this->assertGreaterThan($size - $window, ByteCursor::decode($result->nextCursor)->offset, $engine);
        }
    }

    public function test_last_hour_count_is_bounded_by_the_window(): void
    {
        [, $last] = $this->buildTimedFixture();
        $file = $this->logFile('timed.log');

        foreach ($this->availableEngines() as $engine) {
            $count = $this->searchManager($engine)->count([$file], new SearchCriteria(
                query: 'testing.ERROR',
                dateFrom: date('Y-m-d H:i:s', $last - 3600),
                timeout: 60,
            ), 'minute');

            $this->assertTrue($count->complete, $engine);
            $this->assertSame(3601, $count->total, $engine);
            $this->assertSame($last, $count->last, $engine);
            $range = $count->files[0]['range'];
            $this->assertLessThan(self::LAST_HOUR_BUDGET, $range['end'] - $range['start'], $engine);
        }
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Vendor\LogExplorer\Search\CountResult;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Count / histogram: matches per time bucket, without the lines.
 */
final class CountTest extends TestCase
{
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = (int) strtotime('2026-03-01 00:00:00');
    }

    /** 180 records, one per minute (three hours), every 5th with a trace. */
    private function threeHours(): void
    {
        FixtureGenerator::timed(
            $this->fixture('t.log'),
            array_map(fn (int $i) => $this->t0 + $i * 60, range(0, 179)),
            traceEvery: 5,
        );
    }

    public function test_hour_buckets_on_every_engine(): void
    {
        $this->threeHours();

        foreach ($this->availableEngines() as $engine) {
            $count = $this->searchManager($engine)->count(
                [$this->logFile('t.log')],
                new SearchCriteria(query: 'testing.ERROR'),
                'hour',
            );

            $this->assertSame($engine, $count->engine);
            $this->assertSame('hour', $count->bucket);
            $this->assertSame([
                '2026-03-01 00:00' => 12,
                '2026-03-01 01:00' => 12,
                '2026-03-01 02:00' => 12,
            ], $count->buckets, $engine);
            $this->assertSame(36, $count->total, $engine);
            $this->assertSame($this->t0 + 3 * 60, $count->first, $engine);
            $this->assertSame($this->t0 + 178 * 60, $count->last, $engine);
            $this->assertTrue($count->complete, $engine);
        }
    }

    public function test_a_record_counts_once_however_many_of_its_lines_match(): void
    {
        $this->threeHours();

        foreach ($this->availableEngines() as $engine) {
            // "Handler.php" is on 3 frame lines of each traced record and
            // never on a header line: each traced record must count once.
            $count = $this->searchManager($engine)->count(
                [$this->logFile('t.log')],
                new SearchCriteria(query: 'Handler.php'),
                'day',
            );

            $this->assertSame(36, $count->total, $engine); // records 0, 5, ..., 175
            $this->assertSame(['2026-03-01' => 36], $count->buckets, $engine);
        }
    }

    public function test_count_matches_the_number_of_search_results(): void
    {
        $this->threeHours();
        $file = $this->logFile('t.log');

        foreach ($this->availableEngines() as $engine) {
            foreach (['Event number 1', 'Handler', 'testing.INFO'] as $query) {
                $criteria = new SearchCriteria(query: $query, limit: 1000);
                $search = $this->searchManager($engine)->search($file, $criteria);
                $count = $this->searchManager($engine)->count([$file], $criteria, 'minute');

                $this->assertSame(count($search->matches), $count->total, "{$engine} '{$query}'");
                $this->assertSame(count($search->matches), array_sum($count->buckets), "{$engine} '{$query}'");
            }
        }
    }

    public function test_minute_buckets_within_a_seeked_date_window(): void
    {
        $this->threeHours();

        foreach ($this->availableEngines() as $engine) {
            $count = $this->searchManager($engine)->count(
                [$this->logFile('t.log')],
                new SearchCriteria(
                    query: 'Event number',
                    dateFrom: '2026-03-01 01:58:00',
                    dateTo: '2026-03-01 02:01:00',
                ),
                'minute',
            );

            $this->assertSame([
                '2026-03-01 01:58' => 1,
                '2026-03-01 01:59' => 1,
                '2026-03-01 02:00' => 1,
                '2026-03-01 02:01' => 1,
            ], $count->buckets, $engine);
            $this->assertLessThan(filesize($this->fixture('t.log')) / 2, $count->files[0]['range']['end'] - $count->files[0]['range']['start']);
        }
    }

    public function test_too_many_minute_buckets_are_coarsened(): void
    {
        // MAX_BUCKETS + 1 distinct minutes forces minute -> hour.
        $n = CountResult::MAX_BUCKETS + 1;
        FixtureGenerator::timed($this->fixture('t.log'), (function () use ($n) {
            for ($i = 0; $i < $n; $i++) {
                yield $this->t0 + $i * 60;
            }
        })());

        $count = $this->searchManager('stream')->count([$this->logFile('t.log')], new SearchCriteria(query: 'Event'), 'minute');

        $this->assertSame('hour', $count->bucket);
        $this->assertSame($n, $count->total);
        $this->assertSame($n, array_sum($count->buckets));
        $this->assertSame(60, $count->buckets['2026-03-01 00:00']);
        $this->assertCount((int) ceil($n / 60), $count->buckets);
    }

    public function test_undated_matches_are_counted_but_not_bucketed(): void
    {
        file_put_contents($this->fixture('plain.log'), implode("\n", [
            json_encode(['message' => 'boom one']),
            json_encode(['message' => 'boom two']),
            json_encode(['message' => 'quiet']),
        ])."\n");

        $count = $this->searchManager('stream')->count([$this->logFile('plain.log')], new SearchCriteria(query: 'boom'));

        $this->assertSame(2, $count->total);
        $this->assertSame(2, $count->undated);
        $this->assertSame([], $count->buckets);
        $this->assertNull($count->first);
    }

    public function test_an_unknown_bucket_is_rejected(): void
    {
        $this->threeHours();

        $this->expectException(\InvalidArgumentException::class);
        $this->searchManager('stream')->count([$this->logFile('t.log')], new SearchCriteria(query: 'x'), 'week');
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Vendor\LogExplorer\Exceptions\SearchFailedException;
use Vendor\LogExplorer\Search\CommandLineSearcher;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchResult;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogLine;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Time-window seek + ranged scanning, checked on every engine the host has
 * (stream always; grep and ripgrep when installed) and against the exact,
 * unseeked result.
 */
final class RangedSearchTest extends TestCase
{
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = (int) strtotime('2026-03-01 00:00:00');
    }

    /** 600 records, one per minute; every 5th has a stack trace. */
    private function tenHours(): array
    {
        return FixtureGenerator::timed(
            $this->fixture('t.log'),
            array_map(fn (int $i) => $this->t0 + $i * 60, range(0, 599)),
            traceEvery: 5,
        );
    }

    private function at(int $minute): string
    {
        return date('Y-m-d H:i:s', $this->t0 + $minute * 60);
    }

    /** @return array<int,string> offset => raw, for order-sensitive comparison */
    private function keyed(SearchResult $result): array
    {
        $out = [];
        foreach ($result->matches as $match) {
            $out[$match->offset] = $match->raw;
        }

        return $out;
    }

    private function eventNumbers(SearchResult $result): array
    {
        return array_map(static function (LogLine $m): int {
            preg_match('/Event number (\d+) /', $m->raw, $x);

            return (int) $x[1];
        }, $result->matches);
    }

    /* ------------------------------------------------------------------ */
    /* The seek narrows the range and keeps the result exact               */
    /* ------------------------------------------------------------------ */

    public function test_date_window_seeks_to_a_narrow_range_on_every_engine(): void
    {
        $offsets = $this->tenHours();

        foreach ($this->availableEngines() as $engine) {
            $result = $this->searchManager($engine, ['time_seek_margin' => 0])->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'Event number', dateFrom: $this->at(300), dateTo: $this->at(309)),
            );

            $this->assertSame(range(300, 309), $this->eventNumbers($result), $engine);
            $this->assertTrue($result->complete, $engine);
            $this->assertSame(['start' => $offsets[300], 'end' => $offsets[310]], $result->range, $engine);
        }
    }

    public function test_seeked_results_equal_unseeked_results(): void
    {
        $this->tenHours();
        $file = $this->logFile('t.log');

        $cases = [
            new SearchCriteria(query: 'Event number', dateFrom: $this->at(123)),
            new SearchCriteria(query: 'Event number', dateTo: $this->at(77)),
            new SearchCriteria(query: 'Handler.php', dateFrom: $this->at(41), dateTo: $this->at(97)),
            new SearchCriteria(query: 'event NUMBER', level: 'error', dateFrom: $this->at(500)),
            new SearchCriteria(query: 'Event number [0-9]+5 ', regex: true, dateFrom: $this->at(10), dateTo: $this->at(200)),
        ];

        foreach ($this->availableEngines() as $engine) {
            foreach ($cases as $n => $criteria) {
                $seeked = $this->searchManager($engine)->search($file, $criteria);
                $exact = $this->searchManager($engine, ['time_seek' => false])->search($file, $criteria);

                $this->assertNotEmpty($exact->matches, "{$engine} case {$n} sanity");
                $this->assertSame($this->keyed($exact), $this->keyed($seeked), "{$engine} case {$n}");
                $this->assertGreaterThanOrEqual($exact->range['start'], $seeked->range['start']);
            }
        }
    }

    public function test_filter_only_date_search_is_seeked_too(): void
    {
        $offsets = $this->tenHours();

        $result = $this->searchManager('auto', ['time_seek_margin' => 0])->search(
            $this->logFile('t.log'),
            new SearchCriteria(query: '', level: 'error', dateFrom: $this->at(590)),
        );

        $this->assertSame('stream', $result->engine);
        $this->assertSame($offsets[590], $result->range['start']);
        $this->assertSame([593, 598], $this->eventNumbers($result));
    }

    public function test_skewed_lines_inside_the_margin_are_still_found(): void
    {
        // Strictly one per minute, except record 40 is stamped 3 minutes
        // AHEAD (a worker with a fast clock). It sits before records 41-42
        // in the file but belongs to a "since minute 42" query.
        $stamps = array_map(fn (int $i) => $this->t0 + $i * 60, range(0, 99));
        $stamps[40] = $this->t0 + 43 * 60;
        FixtureGenerator::timed($this->fixture('t.log'), $stamps);

        foreach ($this->availableEngines() as $engine) {
            $result = $this->searchManager($engine, ['time_seek_margin' => 300])->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'Event number', dateFrom: $this->at(42), dateTo: $this->at(45)),
            );

            $this->assertSame([40, 42, 43, 44, 45], $this->eventNumbers($result), $engine);
        }
    }

    public function test_file_without_timestamps_falls_back_to_the_full_range(): void
    {
        // JSON records with no time field: parseable, one record per line,
        // but nothing to seek on.
        $h = fopen($this->fixture('plain.log'), 'wb');
        for ($i = 0; $i < 200; $i++) {
            fwrite($h, json_encode(['level' => 'info', 'message' => "plain line {$i} with no timestamp"])."\n");
        }
        fclose($h);
        $size = (int) filesize($this->fixture('plain.log'));

        foreach ($this->availableEngines() as $engine) {
            $result = $this->searchManager($engine)->search(
                $this->logFile('plain.log'),
                new SearchCriteria(query: 'plain line 19', dateFrom: '2026-03-01 12:00:00'),
            );

            $this->assertSame(['start' => 0, 'end' => $size], $result->range, $engine);
            // Undated records pass the date filter, as they always have.
            $this->assertCount(11, $result->matches, $engine); // 19, 190..199
        }
    }

    /* ------------------------------------------------------------------ */
    /* Cursor resume is O(page): the next page starts at the cursor        */
    /* ------------------------------------------------------------------ */

    public function test_cursor_resume_scans_from_the_cursor_not_from_byte_zero(): void
    {
        $this->tenHours();
        $file = $this->logFile('t.log');

        foreach ($this->availableEngines() as $engine) {
            $manager = $this->searchManager($engine);
            $seen = [];
            $fromOffset = 0;
            $pages = 0;

            do {
                $page = $manager->search($file, new SearchCriteria(query: 'testing.ERROR', limit: 25, fromOffset: $fromOffset));
                $pages++;

                // The engine was handed [cursor, EOF), never [0, EOF).
                $this->assertSame($fromOffset, $page->range['start'], "{$engine} page {$pages}");

                $seen = [...$seen, ...$this->eventNumbers($page)];
                $fromOffset = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
            } while ($fromOffset !== null && $pages < 50);

            $this->assertSame(range(3, 598, 5), $seen, $engine);
            $this->assertSame(5, $pages, $engine);
        }
    }

    public function test_end_offset_bounds_the_scan(): void
    {
        $offsets = $this->tenHours();

        foreach ($this->availableEngines() as $engine) {
            $result = $this->searchManager($engine)->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'Event number', fromOffset: $offsets[100], endOffset: $offsets[110]),
            );

            $this->assertSame(range(100, 109), $this->eventNumbers($result), $engine);
            $this->assertTrue($result->complete, $engine);
        }
    }

    public function test_a_range_starting_mid_record_skips_that_record_on_every_engine(): void
    {
        $offsets = $this->tenHours();
        $contents = (string) file_get_contents($this->fixture('t.log'));

        // A byte inside record 100's stack trace (not a line start either).
        $midTrace = strpos($contents, 'Handler.php', $offsets[100]);

        foreach ($this->availableEngines() as $engine) {
            $result = $this->searchManager($engine)->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'Handler.php', limit: 3, fromOffset: $midTrace),
            );

            // Record 100 started before the range; the next traced records follow.
            $this->assertSame([105, 110, 115], $this->eventNumbers($result), $engine);
            foreach ($result->matches as $match) {
                $this->assertStringStartsWith('[', $match->raw, "{$engine}: orphan continuation returned");
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Multi-file ranged search: one process per file, shared budget       */
    /* ------------------------------------------------------------------ */

    public function test_multi_file_ranged_search_shares_the_limit(): void
    {
        $stamps = array_map(fn (int $i) => $this->t0 + $i * 60, range(0, 99));
        FixtureGenerator::timed($this->fixture('a.log'), $stamps);
        FixtureGenerator::timed($this->fixture('b.log'), $stamps);
        $files = [$this->logFile('a.log'), $this->logFile('b.log')];

        foreach ($this->availableEngines() as $engine) {
            $results = $this->searchManager($engine, ['time_seek_margin' => 0])->searchMany(
                $files,
                new SearchCriteria(query: 'Event number', dateFrom: $this->at(90), limit: 13),
            );

            $this->assertCount(10, $results[0]->result->matches, $engine);
            $this->assertTrue($results[0]->result->complete, $engine);
            $this->assertCount(3, $results[1]->result->matches, $engine);
            $this->assertTrue($results[1]->result->truncated, $engine);
            $this->assertNotNull($results[1]->result->nextCursor, $engine);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Process priority                                                    */
    /* ------------------------------------------------------------------ */

    public function test_cli_commands_run_under_nice_when_available(): void
    {
        $manager = $this->searchManager('grep');
        $prefix = $manager->commandPrefix();

        $searcher = new CommandLineSearcher($this->source(), $this->records(), 'grep', 'grep', true, $manager->options(), $prefix);
        $cmd = $searcher->command(new SearchCriteria(query: 'x'), ['-'], false);

        $this->assertSame($prefix, array_slice($cmd, 0, count($prefix)));
        $this->assertSame('grep', $cmd[count($prefix)]);

        if (is_executable('/usr/bin/nice') || is_executable('/bin/nice')) {
            $this->assertContains('-n', $prefix);
            $this->assertContains('10', $prefix);
        }
        if (PHP_OS_FAMILY === 'Linux' && (is_executable('/usr/bin/ionice') || is_executable('/bin/ionice'))) {
            $this->assertContains('-c3', $prefix);
        }

        $this->assertSame([], $this->searchManager('grep', ['nice' => false, 'ionice' => false])->commandPrefix());
    }

    /* ------------------------------------------------------------------ */
    /* Engine robustness                                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_query_that_looks_like_an_option_is_searched_literally(): void
    {
        file_put_contents($this->fixture('d.log'), implode("\n", [
            '[2026-03-01 00:00:00] testing.INFO: flag -v seen',
            '[2026-03-01 00:00:01] testing.INFO: arg --pre=/bin/echo seen',
            '[2026-03-01 00:00:02] testing.INFO: nothing here',
        ])."\n");

        foreach ($this->availableEngines() as $engine) {
            foreach (['-v' => 'flag -v seen', '--pre=/bin/echo' => 'arg --pre=/bin/echo seen'] as $query => $expected) {
                $result = $this->searchManager($engine)->search($this->logFile('d.log'), new SearchCriteria(query: $query));

                $this->assertCount(1, $result->matches, "{$engine} '{$query}'");
                $this->assertStringEndsWith($expected, $result->matches[0]->raw, "{$engine} '{$query}'");
            }
        }
    }

    public function test_an_engine_error_is_raised_not_reported_as_no_matches(): void
    {
        $this->tenHours();

        foreach (array_diff($this->availableEngines(), ['stream']) as $engine) {
            try {
                $this->searchManager($engine)->search($this->logFile('t.log'), new SearchCriteria(query: 'Event (', regex: true));
                $this->fail("{$engine}: an invalid regex returned a result");
            } catch (SearchFailedException $e) {
                $this->assertStringContainsString("[{$engine}]", $e->getMessage());
            }
        }

        $this->addToAssertionCount(1); // stream-only hosts have no CLI engine to check
    }

    public function test_criteria_rejects_an_unknown_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchCriteria(query: 'x', direction: 'sideways');
    }
}

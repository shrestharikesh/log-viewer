<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchResult;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogLine;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Newest-first search: fixed windows scanned back from the end of the file.
 */
final class BackwardSearchTest extends TestCase
{
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = (int) strtotime('2026-03-01 00:00:00');
    }

    /** 300 records, one per minute, every 4th with a stack trace. */
    private function fixtureFile(): array
    {
        return FixtureGenerator::timed(
            $this->fixture('t.log'),
            array_map(fn (int $i) => $this->t0 + $i * 60, range(0, 299)),
            traceEvery: 4,
        );
    }

    private function eventNumbers(SearchResult $result): array
    {
        return array_map(static function (LogLine $m): int {
            preg_match('/Event number (\d+) /', $m->raw, $x);

            return (int) $x[1];
        }, $result->matches);
    }

    /**
     * Page through a whole backward search, returning every event number in
     * the order received and the number of pages.
     *
     * @return array{0:int[],1:int}
     */
    private function drain(string $engine, SearchCriteria $base, int $window): array
    {
        $manager = $this->searchManager($engine, ['backward_window' => $window]);
        $file = $this->logFile('t.log');
        $seen = [];
        $fromOffset = 0;
        $pages = 0;

        do {
            $page = $manager->search($file, new SearchCriteria(
                query: $base->query,
                level: $base->level,
                dateFrom: $base->dateFrom,
                dateTo: $base->dateTo,
                limit: $base->limit,
                fromOffset: $fromOffset,
                direction: SearchCriteria::BACKWARD,
            ));
            $pages++;

            $this->assertSame(SearchCriteria::BACKWARD, $page->direction);
            $seen = [...$seen, ...$this->eventNumbers($page)];
            $fromOffset = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
        } while ($fromOffset !== null && $pages < 500);

        return [$seen, $pages];
    }

    public function test_first_page_is_the_newest_matches_newest_first(): void
    {
        $this->fixtureFile();

        foreach ($this->availableEngines() as $engine) {
            $page = $this->searchManager($engine)->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'Event number', limit: 5, direction: SearchCriteria::BACKWARD),
            );

            $this->assertSame([299, 298, 297, 296, 295], $this->eventNumbers($page), $engine);
            $this->assertFalse($page->complete, $engine);
            $this->assertTrue($page->truncated, $engine);
        }
    }

    public function test_paging_backward_returns_every_match_exactly_once_in_reverse_order(): void
    {
        $this->fixtureFile();

        foreach ($this->availableEngines() as $engine) {
            // A tiny window forces many windows, several per page, and pages
            // that end in the middle of a window.
            foreach ([700, 5000, 64 * 1024 * 1024] as $window) {
                [$seen, $pages] = $this->drain($engine, new SearchCriteria(query: 'Event number', limit: 7), $window);

                $this->assertSame(range(299, 0), $seen, "{$engine} window {$window}");
                $this->assertSame((int) ceil(300 / 7), $pages, "{$engine} window {$window}");
            }
        }
    }

    public function test_backward_matches_are_whole_records_even_across_window_edges(): void
    {
        $this->fixtureFile();

        foreach ($this->availableEngines() as $engine) {
            // Only the stack-trace frames contain "Handler.php".
            [$seen] = $this->drain($engine, new SearchCriteria(query: 'Handler.php', limit: 10), 333);

            $this->assertSame(range(296, 0, -4), $seen, $engine);
        }
    }

    public function test_backward_honours_the_time_window_and_filters(): void
    {
        $this->fixtureFile();

        foreach ($this->availableEngines() as $engine) {
            [$seen] = $this->drain($engine, new SearchCriteria(
                query: 'Event number',
                level: 'error',
                dateFrom: date('Y-m-d H:i:s', $this->t0 + 100 * 60),
                dateTo: date('Y-m-d H:i:s', $this->t0 + 140 * 60),
                limit: 3,
            ), 1000);

            $this->assertSame([138, 133, 128, 123, 118, 113, 108, 103], $seen, $engine);
        }
    }

    public function test_the_first_page_costs_one_window_not_the_whole_file(): void
    {
        $offsets = $this->fixtureFile();
        $size = (int) filesize($this->fixture('t.log'));

        foreach ($this->availableEngines() as $engine) {
            $page = $this->searchManager($engine, ['backward_window' => 2000])->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'Event number', limit: 3, direction: SearchCriteria::BACKWARD),
            );

            // The cursor is the oldest returned record's header, inside the
            // last 2000-byte window: nothing before it was needed.
            $cursor = ByteCursor::decode($page->nextCursor)->offset;
            $this->assertSame($offsets[297], $cursor, $engine);
            $this->assertGreaterThan($size - 2000 - 1000, $cursor, $engine);
        }
    }

    public function test_windows_never_overlap_on_a_file_without_record_headers(): void
    {
        // No line is a record header, so window edges can't be aligned to
        // one; a fold must still stop at its window's end.
        $h = fopen($this->fixture('t.log'), 'wb');
        for ($i = 0; $i < 600; $i++) {
            fwrite($h, sprintf("plain line %04d\n", $i));
        }
        fclose($h);

        foreach ($this->availableEngines() as $engine) {
            $manager = $this->searchManager($engine, ['backward_window' => 1500]);
            $lines = [];
            $fromOffset = 0;
            $pages = 0;

            do {
                $page = $manager->search($this->logFile('t.log'), new SearchCriteria(
                    query: 'plain line', limit: 4, fromOffset: $fromOffset, direction: SearchCriteria::BACKWARD,
                ));
                foreach ($page->matches as $match) {
                    foreach (explode("\n", $match->raw) as $line) {
                        $lines[] = $line;
                    }
                }
                $fromOffset = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
            } while ($fromOffset !== null && ++$pages < 500);

            sort($lines);
            $this->assertSame(array_values(array_unique($lines)), $lines, "{$engine}: a line was returned twice");
            $this->assertCount(600, $lines, "{$engine}: a line was lost");
        }
    }

    public function test_a_backward_search_with_no_matches_is_complete(): void
    {
        $this->fixtureFile();

        foreach ($this->availableEngines() as $engine) {
            $page = $this->searchManager($engine, ['backward_window' => 1000])->search(
                $this->logFile('t.log'),
                new SearchCriteria(query: 'no such text anywhere', direction: SearchCriteria::BACKWARD),
            );

            $this->assertSame([], $page->matches, $engine);
            $this->assertTrue($page->complete, $engine);
            $this->assertNull($page->nextCursor, $engine);
        }
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Symfony\Component\Process\ExecutableFinder;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Search\CommandLineSearcher;
use Vendor\LogExplorer\Search\RangeScanner;
use Vendor\LogExplorer\Search\ScanOptions;
use Vendor\LogExplorer\Search\ScanOutcome;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchResult;
use Vendor\LogExplorer\Search\SearchRunner;
use Vendor\LogExplorer\Search\StreamSearcher;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Edge cases where an engine is slow, a file vanishes mid-search, or count
 * and search must agree on what a "record" is.
 */
final class SearchRobustnessTest extends TestCase
{
    public function test_backward_search_progresses_when_a_full_window_cannot_finish_in_time(): void
    {
        [, $records] = FixtureGenerator::timedOfSize($this->fixture('big.log'), 3 * 1024 * 1024, (int) strtotime('2026-03-01'));
        $file = $this->logFile('big.log');
        $assembler = $this->records();

        // An engine that "times out" on any range over 1.5 MB, e.g. the
        // stream engine under idle-priority IO with the default 64 MB window.
        $slow = new class(new StreamSearcher($this->source(), $assembler)) implements RangeScanner {
            public function __construct(private readonly StreamSearcher $inner)
            {
            }

            public function name(): string
            {
                return 'slow';
            }

            public function available(LogFile $file): bool
            {
                return true;
            }

            public function search(LogFile $file, SearchCriteria $criteria): SearchResult
            {
                return $this->inner->search($file, $criteria);
            }

            public function searchMany(array $files, SearchCriteria $criteria): array
            {
                return $this->inner->searchMany($files, $criteria);
            }

            public function scanRange(LogFile $file, SearchCriteria $criteria, int $start, int $end, float $deadline, callable $onMatch, bool $headersOnly = false): ScanOutcome
            {
                if ($end - $start > 1536 * 1024) {
                    return new ScanOutcome($start, false, true);
                }

                return $this->inner->scanRange($file, $criteria, $start, $end, $deadline, $onMatch, $headersOnly);
            }
        };

        $runner = new SearchRunner($slow, $this->source(), $assembler, new ScanOptions(backwardWindow: 64 * 1024 * 1024));

        $offsets = [];
        $cursor = 0;
        $pages = 0;
        do {
            $page = $runner->backward([$file], new SearchCriteria(
                query: 'testing.ERROR',
                limit: 1_000_000,
                fromOffset: $cursor,
                direction: SearchCriteria::BACKWARD,
            ))[0]->result;
            $pages++;

            foreach ($page->matches as $m) {
                $offsets[] = $m->offset;
            }

            $next = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
            if ($next !== null && $cursor !== 0) {
                $this->assertLessThan($cursor, $next, 'every page must move the cursor');
            }
            $cursor = $next;
        } while ($cursor !== null && $pages < 50);

        $this->assertNull($cursor, 'backward search must reach the start of the file');
        $this->assertGreaterThan(1, $pages);
        $this->assertCount((int) ceil($records / 10), $offsets);

        $sorted = $offsets;
        rsort($sorted);
        $this->assertSame($sorted, $offsets, 'newest first across pages');
    }

    public function test_a_cli_search_that_times_out_before_its_first_match_still_advances_the_cursor(): void
    {
        if ((new ExecutableFinder())->find('grep') === null) {
            $this->markTestSkipped('grep binary not available on this host.');
        }

        $text = '';
        for ($i = 0; $i < 400; $i++) {
            $text .= "[2026-11-05 10:00:00] local.INFO: line {$i}\n";
        }
        file_put_contents($this->fixture('slow.log'), $text);
        $file = $this->logFile('slow.log');

        // grep that stalls on any input over 6 KB: small pieces finish, a big
        // one (or the whole file at once) times out before printing anything.
        $prefix = ['sh', '-c', 'f=$(mktemp); cat > "$f"; if [ "$(wc -c < "$f")" -gt 6000 ]; then sleep 3; fi; "$0" "$@" < "$f"; s=$?; rm -f "$f"; exit $s'];
        $engine = new CommandLineSearcher($this->source(), $this->records(), 'grep', 'grep', true, new ScanOptions(forwardPiece: 2000), $prefix);

        // Start past the first record, so the range is fed on stdin (and the
        // wrapper sees its size) on every engine version.
        $cursor = strlen("[2026-11-05 10:00:00] local.INFO: line 0\n");
        $found = [];
        $pages = 0;
        do {
            $page = $engine->search($file, new SearchCriteria(query: 'line 399', limit: 10, fromOffset: $cursor, timeout: 1));
            $pages++;
            $found = [...$found, ...$page->matches];

            $next = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
            if ($next !== null) {
                $this->assertGreaterThan($cursor, $next, 'every page must move the cursor');
            }
            $cursor = $next;
        } while ($cursor !== null && $pages < 20);

        $this->assertNull($cursor, 'paging must finish');
        $this->assertGreaterThan(1, $pages, 'the slow grep must have timed out at least once');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('line 399', $found[0]->raw);

        // A count that times out reports progress the same way.
        $count = (new SearchRunner($engine, $this->source(), $this->records()))
            ->count([$file], new SearchCriteria(query: 'line 399', timeout: 1));
        $this->assertFalse($count->complete);
        $this->assertGreaterThan(0, ByteCursor::decode($count->files[0]['cursor'])->offset);
    }

    public function test_a_multi_file_cli_search_that_times_out_before_its_first_match_still_advances_every_call(): void
    {
        $names = ['a.log', 'b.log', 'c.log'];
        $files = [];
        foreach ($names as $name) {
            $text = '';
            for ($i = 0; $i < 300; $i++) {
                $text .= "[2026-11-05 10:00:00] local.INFO: {$name} line {$i}\n";
            }
            file_put_contents($this->fixture($name), $text);
            $files[] = $this->logFile($name);
        }

        // Pieces start at 2000 bytes; the engine stalls on more than 6000
        // bytes of input (stdin or file arguments), so a 13 KB file, or all
        // three at once, never finishes inside the 1 s budget.
        foreach ($this->stallingEngines(6000, new ScanOptions(forwardPiece: 2000)) as $label => $engine) {
            // A client following next_cursor per file. Files that sit at the
            // same cursor go in one call, so every call that starts a file
            // from 0 alongside another is a multi-file, whole-file search.
            $cursors = array_fill(0, count($files), 0);
            $found = array_fill(0, count($files), []);
            $calls = 0;
            $multiFileCalls = 0;

            while (($first = array_key_first(array_filter($cursors, static fn ($c) => $c !== null))) !== null && $calls < 30) {
                $offset = $cursors[$first];
                $group = array_keys(array_filter($cursors, static fn ($c) => $c === $offset));

                $results = $engine->searchMany(
                    array_map(static fn (int $i) => $files[$i], $group),
                    new SearchCriteria(query: 'line 299', limit: 10, fromOffset: $offset, timeout: 1),
                );
                $calls++;
                $multiFileCalls += count($group) > 1 ? 1 : 0;

                foreach ($group as $k => $i) {
                    $page = $results[$k]->result;
                    $found[$i] = [...$found[$i], ...$page->matches];
                    $cursors[$i] = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
                }

                if ($cursors[$first] !== null) {
                    $this->assertGreaterThan($offset, $cursors[$first], "{$label}: call {$calls} must move {$names[$first]}'s cursor");
                }
            }

            $this->assertSame(array_fill(0, count($files), null), $cursors, "{$label}: paging must finish");
            $this->assertGreaterThan(1, $multiFileCalls, "{$label}: the multi-file path must have been exercised");
            $this->assertGreaterThan(count($files), $calls, "{$label}: the slow engine must have timed out");

            foreach ($names as $i => $name) {
                $this->assertCount(1, $found[$i], "{$label}: {$name}");
                $this->assertStringContainsString("{$name} line 299", $found[$i][0]->raw, $label);
            }
        }
    }

    public function test_forward_pieces_start_small_so_a_slow_call_still_completes_one(): void
    {
        [, $records] = FixtureGenerator::timedOfSize($this->fixture('big.log'), 3 * 1024 * 1024, (int) strtotime('2026-03-01'));
        $file = $this->logFile('big.log');

        // Default options (forward_piece 64 MB) against an engine that stalls
        // on more than 1.5 MB of input, e.g. idle-priority IO on a busy disk.
        foreach ($this->stallingEngines(1536 * 1024, new ScanOptions()) as $label => $engine) {
            $cursor = 0;
            $count = 0;
            $pages = 0;
            do {
                $page = $engine->search($file, new SearchCriteria(query: 'testing.ERROR', limit: 1_000_000, fromOffset: $cursor, timeout: 1));
                $pages++;
                $count += count($page->matches);

                $next = $page->nextCursor === null ? null : ByteCursor::decode($page->nextCursor)->offset;
                if ($next !== null) {
                    $this->assertGreaterThan($cursor, $next, "{$label}: page {$pages} must move the cursor");
                }
                $cursor = $next;
            } while ($cursor !== null && $pages < 20);

            $this->assertNull($cursor, "{$label}: paging must finish");
            $this->assertGreaterThan(1, $pages, "{$label}: the slow engine must have timed out");
            $this->assertSame((int) ceil($records / 10), $count, $label);
        }
    }

    /**
     * The rg/grep engines available on this host, each wrapped so that it
     * sleeps past any timeout when given more than $stallOver bytes in total
     * (stdin plus file arguments). Deterministic, whatever the disk speed.
     *
     * @return array<string,CommandLineSearcher>
     */
    private function stallingEngines(int $stallOver, ScanOptions $options): array
    {
        $wrapper = ['sh', '-c', 'f=$(mktemp); cat > "$f"; n=$(wc -c < "$f"); '
            .'for a in "$@"; do if [ -f "$a" ]; then n=$((n + $(wc -c < "$a"))); fi; done; '
            .'if [ "$n" -gt '.$stallOver.' ]; then sleep 3; fi; '
            .'"$0" "$@" < "$f"; s=$?; rm -f "$f"; exit $s'];

        $finder = new ExecutableFinder();
        $engines = [];
        foreach (['grep' => 'grep', 'ripgrep' => 'rg'] as $engine => $binary) {
            if ($finder->find($binary, null, ['/usr/local/bin', '/opt/homebrew/bin']) !== null) {
                $engines[$engine] = new CommandLineSearcher($this->source(), $this->records(), $engine, $binary, true, $options, $wrapper);
            }
        }

        if ($engines === []) {
            $this->markTestSkipped('Neither grep nor rg is available on this host.');
        }

        return $engines;
    }

    public function test_count_folds_header_less_lines_into_the_same_records_search_returns(): void
    {
        $text = '';
        for ($i = 0; $i < 500; $i++) {
            $text .= "plain boom line {$i}\n";
        }
        file_put_contents($this->fixture('plain.log'), $text);
        $file = $this->logFile('plain.log');

        foreach ($this->availableEngines() as $engine) {
            $criteria = new SearchCriteria(query: 'boom', limit: 1000);
            $search = $this->searchManager($engine)->search($file, $criteria);
            $count = $this->searchManager($engine)->count([$file], $criteria, 'hour');

            // 500 orphan lines fold into records of 1 + 200 continuation lines.
            $this->assertCount(3, $search->matches, $engine);
            $this->assertSame(3, $count->total, $engine);
        }
    }

    public function test_a_file_that_vanished_mid_batch_does_not_discard_the_other_files_matches(): void
    {
        if ((new ExecutableFinder())->find('grep') === null) {
            $this->markTestSkipped('grep binary not available on this host.');
        }

        FixtureGenerator::laravelLines($this->fixture('a.log'), 10, 0);
        FixtureGenerator::laravelLines($this->fixture('gone.log'), 10, 100);
        $a = $this->logFile('a.log');
        $gone = $this->logFile('gone.log');
        unlink($gone->path); // rotated away after it was resolved

        $source = new class($this->source()) implements LogSourceInterface {
            public function __construct(private readonly LogSourceInterface $inner)
            {
            }

            public function name(): string
            {
                return $this->inner->name();
            }

            public function files(): array
            {
                return $this->inner->files();
            }

            public function resolve(string $identifier): LogFile
            {
                return $this->inner->resolve($identifier);
            }

            public function exists(LogFile $file): bool
            {
                return true;
            }

            public function size(LogFile $file): int
            {
                return is_file($file->path) ? $this->inner->size($file) : 0;
            }

            public function lastModified(LogFile $file): int
            {
                return $this->inner->lastModified($file);
            }

            public function reader(LogFile $file)
            {
                return $this->inner->reader($file);
            }

            public function localPath(LogFile $file): ?string
            {
                // Still handed to grep, as if it vanished after the stat.
                return $file->path;
            }
        };

        $engine = new CommandLineSearcher($source, $this->records(), 'grep', 'grep', true);
        $results = $engine->searchMany([$a, $gone], new SearchCriteria(query: 'testing.ERROR', limit: 100));

        $this->assertCount(2, $results[0]->result->matches);
        $this->assertSame([], $results[1]->result->matches);
    }
}

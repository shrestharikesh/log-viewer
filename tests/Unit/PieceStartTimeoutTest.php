<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Symfony\Component\Process\ExecutableFinder;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Search\CommandLineSearcher;
use Vendor\LogExplorer\Search\ScanOptions;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchRunner;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Support\LogLine;
use Vendor\LogExplorer\Tests\Support\StallingLogSource;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * A piece whose process can't even be launched inside the remaining budget
 * must end the scan as a resumable timeout, never as an exception.
 *
 * Symfony's Process::start() checks the timeout before returning, so a
 * piece started with a few milliseconds left can time out inside start().
 * These tests force that deterministically: StallingLogSource makes the
 * stdin generator (pulled inside start()) slower than the budget.
 */
final class PieceStartTimeoutTest extends TestCase
{
    private const LINES = 400;

    /** @return array{0:int,1:int} [first line length, file size] */
    private function fixtureFile(string $name = 'pieces.log'): array
    {
        $text = '';
        for ($i = 0; $i < self::LINES; $i++) {
            $text .= "[2026-11-05 10:00:00] local.INFO: line {$i}\n";
        }
        file_put_contents($this->fixture($name), $text);

        return [strlen("[2026-11-05 10:00:00] local.INFO: line 0\n"), strlen($text)];
    }

    /**
     * The rg/grep engines on this host, over the given source.
     *
     * @return array<string,CommandLineSearcher>
     */
    private function engines(LogSourceInterface $source, ScanOptions $options = new ScanOptions()): array
    {
        $finder = new ExecutableFinder();
        $engines = [];
        foreach (['grep' => 'grep', 'ripgrep' => 'rg'] as $engine => $binary) {
            if ($finder->find($binary, null, ['/usr/local/bin', '/opt/homebrew/bin']) !== null) {
                $engines[$engine] = new CommandLineSearcher($source, $this->records(), $engine, $binary, true, $options);
            }
        }

        if ($engines === []) {
            $this->markTestSkipped('Neither grep nor rg is available on this host.');
        }

        return $engines;
    }

    /** @param  LogLine[]  $matches */
    private function lineNumbers(array $matches): array
    {
        return array_map(static function (LogLine $m): int {
            preg_match('/line (\d+)$/', $m->raw, $x);

            return (int) $x[1];
        }, $matches);
    }

    public function test_a_piece_whose_process_launch_outruns_the_deadline_times_out_instead_of_throwing(): void
    {
        [$firstLine, $size] = $this->fixtureFile();
        $file = $this->logFile('pieces.log');

        foreach (array_keys($this->engines($this->source())) as $label) {
            // The range fits in the first piece and starts past byte 0, so the
            // only reader() call is the stdin generator inside start().
            $source = new StallingLogSource($this->source(), 0.4, stallFromRead: 1);
            $engine = $this->engines($source)[$label];

            $matches = [];
            $outcome = $engine->scanRange(
                $file,
                new SearchCriteria(query: 'local.INFO'),
                $firstLine,
                $size,
                microtime(true) + 0.2,
                static function (LogLine $r) use (&$matches): bool {
                    $matches[] = $r;

                    return true;
                },
            );

            $this->assertSame(1, $source->stalls, "{$label}: the launch must have been stalled");
            $this->assertFalse($outcome->completed, $label);
            $this->assertTrue($outcome->timedOut, $label);
            $this->assertSame($firstLine, $outcome->resumeOffset, "{$label}: nothing completed, so resume where asked");
            $this->assertSame([], $matches, $label);
        }
    }

    public function test_a_deadline_that_expires_between_pieces_resumes_after_the_last_completed_piece(): void
    {
        [, $size] = $this->fixtureFile();
        $file = $this->logFile('pieces.log');
        $options = new ScanOptions(forwardPiece: 2000);

        foreach (array_keys($this->engines($this->source(), $options)) as $label) {
            // reader() #1 is the record-boundary stream, #2 piece 1's stdin,
            // #3 piece 2's stdin: piece 1 runs normally, piece 2's launch
            // outlasts what is left of the deadline.
            $source = new StallingLogSource($this->source(), 0.8, stallFromRead: 3);
            $engine = $this->engines($source, $options)[$label];

            $matches = [];
            $collect = static function (LogLine $r) use (&$matches): bool {
                $matches[] = $r;

                return true;
            };
            $outcome = $engine->scanRange($file, new SearchCriteria(query: 'local.INFO'), 0, $size, microtime(true) + 0.5, $collect);

            $this->assertSame(1, $source->stalls, "{$label}: piece 2's launch must have been stalled");
            $this->assertFalse($outcome->completed, $label);
            $this->assertTrue($outcome->timedOut, $label);
            $this->assertNotSame([], $matches, "{$label}: piece 1 completed");
            $this->assertSame(end($matches)->endOffset, $outcome->resumeOffset, "{$label}: resume at the end of piece 1");

            // Resuming there yields the rest, with no gap and no repeat.
            $engine = $this->engines($this->source(), $options)[$label];
            $rest = $engine->scanRange($file, new SearchCriteria(query: 'local.INFO'), $outcome->resumeOffset, $size, microtime(true) + 30, $collect);

            $this->assertTrue($rest->completed, $label);
            $this->assertSame(range(0, self::LINES - 1), $this->lineNumbers($matches), $label);
        }
    }

    public function test_no_piece_starts_with_less_than_the_minimum_budget_left(): void
    {
        [$firstLine, $size] = $this->fixtureFile();
        $file = $this->logFile('pieces.log');

        foreach (array_keys($this->engines($this->source())) as $label) {
            // Counts reader() calls only. Starting past byte 0 means any
            // started piece opens a reader for its stdin.
            $source = new StallingLogSource($this->source(), 0.0);
            $engine = $this->engines($source)[$label];

            foreach ([0.000001, 0.00001, 0.0001, 0.001, 0.01, 0.04] as $left) {
                $outcome = $engine->scanRange(
                    $file,
                    new SearchCriteria(query: 'local.INFO'),
                    $firstLine,
                    $size,
                    microtime(true) + $left,
                    static fn (LogLine $r): bool => true,
                );

                $this->assertFalse($outcome->completed, "{$label} {$left}s");
                $this->assertTrue($outcome->timedOut, "{$label} {$left}s");
                $this->assertSame($firstLine, $outcome->resumeOffset, "{$label} {$left}s");
            }

            $this->assertSame(0, $source->reads, "{$label}: no piece may start with under the minimum budget left");
        }
    }

    public function test_a_count_whose_next_piece_cannot_launch_in_time_returns_a_resumable_cursor(): void
    {
        [, $size] = $this->fixtureFile();
        $file = $this->logFile('pieces.log');
        $options = new ScanOptions(forwardPiece: 2000);

        foreach (array_keys($this->engines($this->source(), $options)) as $label) {
            // Same read order as above: piece 2's launch outlasts the 1 s timeout.
            $source = new StallingLogSource($this->source(), 1.2, stallFromRead: 3);
            $engine = $this->engines($source, $options)[$label];

            $first = (new SearchRunner($engine, $this->source(), $this->records(), $options))
                ->count([$file], new SearchCriteria(query: 'local.INFO', timeout: 1));

            $this->assertSame(1, $source->stalls, "{$label}: piece 2's launch must have been stalled");
            $this->assertFalse($first->complete, $label);
            $this->assertGreaterThan(0, $first->total, "{$label}: piece 1 was counted");
            $cursor = ByteCursor::decode($first->files[0]['cursor'])->offset;
            $this->assertGreaterThan(0, $cursor, $label);
            $this->assertLessThan($size, $cursor, $label);

            // Resuming from the cursor counts exactly the remainder.
            $engine = $this->engines($this->source(), $options)[$label];
            $rest = (new SearchRunner($engine, $this->source(), $this->records(), $options))
                ->count([$file], new SearchCriteria(query: 'local.INFO', fromOffset: $cursor, timeout: 30));

            $this->assertTrue($rest->complete, $label);
            $this->assertSame(self::LINES, $first->total + $rest->total, $label);
        }
    }
}

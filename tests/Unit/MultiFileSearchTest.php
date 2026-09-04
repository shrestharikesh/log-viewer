<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Illuminate\Config\Repository;
use Symfony\Component\Process\ExecutableFinder;
use Vendor\LogExplorer\Search\CommandLineSearcher;
use Vendor\LogExplorer\Search\FileSearchResult;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchManager;
use Vendor\LogExplorer\Search\StreamSearcher;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Multi-file search: one shared limit/timeout budget across a batch of files,
 * with every match still attributed back to the file it came from.
 */
final class MultiFileSearchTest extends TestCase
{
    /**
     * a.log holds "Message number 0..9", b.log holds "Message number 100..109".
     * FixtureGenerator cycles levels every 5 lines, so each file gets exactly
     * two `testing.ERROR` lines.
     */
    private function twoFixtures(): array
    {
        FixtureGenerator::laravelLines($this->fixture('a.log'), 10, 0);
        FixtureGenerator::laravelLines($this->fixture('b.log'), 10, 100);

        return [$this->logFile('a.log'), $this->logFile('b.log')];
    }

    private function manager(string $mode): SearchManager
    {
        $config = new Repository([
            'log-explorer' => [
                'search' => [
                    'mode' => $mode,
                    'ripgrep' => ['binary' => 'rg', 'enabled' => true],
                    'grep' => ['binary' => 'grep', 'enabled' => true],
                ],
            ],
        ]);

        return new SearchManager($this->source(), $this->scanner(), $this->parserManager(), $config);
    }

    private function skipWithoutGrep(): void
    {
        if ((new ExecutableFinder())->find('grep') === null) {
            $this->markTestSkipped('grep binary not available on this host.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Per-file attribution                                                */
    /* ------------------------------------------------------------------ */

    public function test_command_line_search_many_attributes_matches_to_their_own_file(): void
    {
        $this->skipWithoutGrep();

        [$a, $b] = $this->twoFixtures();

        $results = $this->manager('grep')->searchMany([$a, $b], new SearchCriteria(query: 'testing.ERROR'));

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(FileSearchResult::class, $results);

        // Results come back in the order the files were passed in.
        $this->assertSame($a->identifier, $results[0]->file->identifier);
        $this->assertSame($b->identifier, $results[1]->file->identifier);

        $this->assertSame('grep', $results[0]->result->engine);

        // a.log: messages 3 and 8. b.log: messages 103 and 108.
        $this->assertCount(2, $results[0]->result->matches);
        $this->assertCount(2, $results[1]->result->matches);

        foreach ($results[0]->result->matches as $match) {
            $this->assertMatchesRegularExpression('/Message number [38] /', $match->raw);
        }
        foreach ($results[1]->result->matches as $match) {
            $this->assertMatchesRegularExpression('/Message number 10[38] /', $match->raw);
        }
    }

    public function test_stream_search_many_attributes_matches_to_their_own_file(): void
    {
        [$a, $b] = $this->twoFixtures();

        $results = $this->manager('stream')->searchMany([$a, $b], new SearchCriteria(query: 'testing.ERROR'));

        $this->assertCount(2, $results);
        $this->assertSame('stream', $results[0]->result->engine);
        $this->assertSame($a->identifier, $results[0]->file->identifier);
        $this->assertSame($b->identifier, $results[1]->file->identifier);

        $this->assertCount(2, $results[0]->result->matches);
        $this->assertCount(2, $results[1]->result->matches);

        foreach ($results[1]->result->matches as $match) {
            $this->assertStringContainsString('Message number 10', $match->raw);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Single process invocation                                           */
    /* ------------------------------------------------------------------ */

    public function test_command_line_searcher_builds_one_invocation_for_all_files(): void
    {
        $searcher = new CommandLineSearcher($this->source(), $this->parserManager(), 'grep', 'grep', true);

        $method = (new \ReflectionClass($searcher))->getMethod('buildCommand');
        $method->setAccessible(true);

        $cmd = $method->invoke(
            $searcher,
            new SearchCriteria(query: 'boom'),
            ['/tmp/one.log', '/tmp/two.log'],
            true,
        );

        // A single argv: one binary, both files, filename prefixing forced on.
        $this->assertSame('grep', $cmd[0]);
        $this->assertSame(1, array_count_values($cmd)['grep']);
        $this->assertContains('-H', $cmd);
        $this->assertContains('/tmp/one.log', $cmd);
        $this->assertContains('/tmp/two.log', $cmd);
        $this->assertSame('/tmp/two.log', $cmd[array_key_last($cmd)]);
    }

    public function test_ripgrep_multi_file_invocation_requests_filenames(): void
    {
        $searcher = new CommandLineSearcher($this->source(), $this->parserManager(), 'ripgrep', 'rg', true);

        $method = (new \ReflectionClass($searcher))->getMethod('buildCommand');
        $method->setAccessible(true);

        $multi = $method->invoke($searcher, new SearchCriteria(query: 'boom'), ['/tmp/one.log', '/tmp/two.log'], true);
        $this->assertContains('--with-filename', $multi);
        $this->assertNotContains('--no-filename', $multi);

        // The existing single-file path must keep suppressing the filename.
        $single = $method->invoke($searcher, new SearchCriteria(query: 'boom'), ['/tmp/one.log'], false);
        $this->assertContains('--no-filename', $single);
        $this->assertNotContains('--with-filename', $single);
    }

    /* ------------------------------------------------------------------ */
    /* Shared limit budget                                                 */
    /* ------------------------------------------------------------------ */

    public function test_command_line_search_many_shares_the_limit_across_the_batch(): void
    {
        $this->skipWithoutGrep();

        [$a, $b] = $this->twoFixtures();

        // 4 matches exist in total (2 per file); the batch may return only 3.
        $results = $this->manager('grep')->searchMany(
            [$a, $b],
            new SearchCriteria(query: 'testing.ERROR', limit: 3),
        );

        $total = array_sum(array_map(fn (FileSearchResult $r) => count($r->result->matches), $results));
        $this->assertSame(3, $total);

        // A truncated batch must be resumable, not silently reported as done.
        foreach ($results as $result) {
            $this->assertFalse($result->result->complete);
            $this->assertTrue($result->result->truncated);
            $this->assertNotNull($result->result->nextCursor);
        }
    }

    public function test_stream_search_many_shares_the_limit_across_the_batch(): void
    {
        [$a, $b] = $this->twoFixtures();

        $results = $this->manager('stream')->searchMany(
            [$a, $b],
            new SearchCriteria(query: 'testing.ERROR', limit: 3),
        );

        $total = array_sum(array_map(fn (FileSearchResult $r) => count($r->result->matches), $results));
        $this->assertSame(3, $total);

        // The first file is exhausted first, so the shortfall lands on the second.
        $this->assertCount(2, $results[0]->result->matches);
        $this->assertCount(1, $results[1]->result->matches);
    }

    public function test_search_many_is_complete_when_the_batch_fits_inside_the_limit(): void
    {
        [$a, $b] = $this->twoFixtures();

        $results = $this->manager('stream')->searchMany(
            [$a, $b],
            new SearchCriteria(query: 'testing.ERROR', limit: 100),
        );

        foreach ($results as $result) {
            $this->assertTrue($result->result->complete);
            $this->assertFalse($result->result->truncated);
            $this->assertNull($result->result->nextCursor);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Shared timeout budget                                               */
    /* ------------------------------------------------------------------ */

    public function test_stream_search_many_stops_early_once_the_shared_timeout_is_spent(): void
    {
        [$a, $b] = $this->twoFixtures();

        // A zero-second budget is already spent, so the batch bails out
        // immediately instead of paying the cost once per file.
        $results = $this->manager('stream')->searchMany(
            [$a, $b],
            new SearchCriteria(query: 'testing.ERROR', timeout: 0),
        );

        $this->assertCount(2, $results);

        // The second file must not have been scanned at all.
        $this->assertSame([], $results[1]->result->matches);
        $this->assertFalse($results[1]->result->complete);
        $this->assertNotNull($results[1]->result->nextCursor);
    }

    /* ------------------------------------------------------------------ */
    /* Backend selection + edge cases                                      */
    /* ------------------------------------------------------------------ */

    public function test_filter_only_criteria_uses_the_stream_backend(): void
    {
        [$a, $b] = $this->twoFixtures();

        $criteria = new SearchCriteria(query: '', level: 'error');

        $this->assertInstanceOf(
            StreamSearcher::class,
            $this->manager('auto')->resolveMany([$a, $b], $criteria),
        );

        $results = $this->manager('auto')->searchMany([$a, $b], $criteria);

        foreach ($results as $result) {
            $this->assertSame('stream', $result->result->engine);
            $this->assertCount(2, $result->result->matches);
            foreach ($result->result->matches as $match) {
                $this->assertSame('error', $match->parsed?->level);
            }
        }
    }

    public function test_search_many_with_no_files_returns_an_empty_array(): void
    {
        $this->assertSame([], $this->manager('auto')->searchMany([], new SearchCriteria(query: 'x')));
    }

    public function test_search_many_of_a_single_file_matches_the_single_file_search(): void
    {
        $this->skipWithoutGrep();

        FixtureGenerator::laravelLines($this->fixture('a.log'), 40);
        $file = $this->logFile('a.log');
        $criteria = new SearchCriteria(query: 'testing.ERROR');

        $manager = $this->manager('grep');
        $single = $manager->search($file, $criteria);
        $batch = $manager->searchMany([$file], $criteria);

        $this->assertCount(1, $batch);
        $this->assertSame($file->identifier, $batch[0]->file->identifier);
        $this->assertSame(
            array_map(fn ($m) => $m->raw, $single->matches),
            array_map(fn ($m) => $m->raw, $batch[0]->result->matches),
        );
        $this->assertSame($single->complete, $batch[0]->result->complete);
    }

    /* ------------------------------------------------------------------ */
    /* Regression: the existing single-file API is untouched               */
    /* ------------------------------------------------------------------ */

    public function test_single_file_search_still_works_on_both_backends(): void
    {
        FixtureGenerator::laravelLines($this->fixture('a.log'), 100);
        $file = $this->logFile('a.log');

        $stream = $this->manager('stream')->search($file, new SearchCriteria(query: 'Message number 42 '));
        $this->assertCount(1, $stream->matches);
        $this->assertSame('stream', $stream->engine);
        $this->assertTrue($stream->complete);

        if ((new ExecutableFinder())->find('grep') !== null) {
            $grep = $this->manager('grep')->search($file, new SearchCriteria(query: 'Message number 42 '));
            $this->assertCount(1, $grep->matches);
            $this->assertSame('grep', $grep->engine);
            $this->assertSame($stream->matches[0]->raw, $grep->matches[0]->raw);
        }
    }

    public function test_file_search_result_serializes_with_its_file_attribution(): void
    {
        [$a] = $this->twoFixtures();

        $results = $this->manager('stream')->searchMany([$a], new SearchCriteria(query: 'testing.ERROR'));
        $json = $results[0]->jsonSerialize();

        $this->assertSame($a->identifier, $json['file']['identifier']);
        $this->assertSame('a.log', $json['file']['name']);
        $this->assertArrayHasKey('matches', $json);
        $this->assertSame(2, $json['meta']['count']);
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Illuminate\Config\Repository;
use Symfony\Component\Process\ExecutableFinder;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Search\CommandLineSearcher;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Search\SearchManager;
use Vendor\LogExplorer\Search\StreamSearcher;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Regression coverage for the bug where a multi-line Laravel/Monolog record
 * (header + "[stacktrace]" + "#N ..." frames) rendered as N separate,
 * header-less matches instead of the one logical entry it actually is — see
 * RecordAssembler. `withStackTrace()` fixtures a 3-frame exception between
 * two ordinary single-line entries.
 */
final class MultiLineRecordTest extends TestCase
{
    private function manager(string $mode): SearchManager
    {
        $config = new Repository([
            'log-explorer' => [
                'search' => [
                    'mode' => $mode,
                    'ripgrep' => ['binary' => 'rg', 'enabled' => true],
                    'grep' => ['binary' => 'grep', 'enabled' => true],
                ],
                'parsing' => ['max_continuation_lines' => 200],
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
    /* Browsing (no text query) — the exact scenario from the bug report   */
    /* ------------------------------------------------------------------ */

    public function test_stream_search_folds_a_stack_trace_into_one_match_when_browsing(): void
    {
        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');

        // A filter-only, no-text-query browse — what the UI sends when a level
        // or date range is picked without typing a search term.
        $result = $this->manager('stream')->search($file, new SearchCriteria(query: '', level: null));

        $this->assertCount(3, $result->matches, 'expected 3 records: before, the exception, after');
        $this->assertSame('before the exception', $result->matches[0]->parsed?->message);
        $this->assertStringStartsWith('Something failed badly', $result->matches[1]->parsed?->message ?? '');
        $this->assertSame('after the exception', $result->matches[2]->parsed?->message);

        // The frames are folded into the record, not dropped.
        $this->assertStringContainsString('[stacktrace]', $result->matches[1]->raw);
        $this->assertStringContainsString('#0 ', $result->matches[1]->raw);
        $this->assertStringContainsString('#2 ', $result->matches[1]->raw);
        // [stacktrace] + 3 frames + "#3 {main}" + the closing '"}' line.
        $this->assertCount(6, $result->matches[1]->parsed?->extra ?? []);
    }

    public function test_command_line_search_folds_a_stack_trace_into_one_match_when_browsing(): void
    {
        $this->skipWithoutGrep();

        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');

        $result = $this->manager('grep')->search($file, new SearchCriteria(query: 'failed badly'));

        $this->assertCount(1, $result->matches);
        $this->assertStringContainsString('[stacktrace]', $result->matches[0]->raw);
        $this->assertStringContainsString('#0 ', $result->matches[0]->raw);
    }

    /* ------------------------------------------------------------------ */
    /* Match-anywhere-in-block: a query that only hits a continuation line */
    /* ------------------------------------------------------------------ */

    public function test_stream_search_matches_text_found_only_in_a_stack_frame(): void
    {
        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');

        // "Handler->handle" only appears inside the folded frames, never on
        // the header line itself.
        $result = $this->manager('stream')->search($file, new SearchCriteria(query: 'Handler->handle'));

        $this->assertCount(1, $result->matches);
        $this->assertStringStartsWith('Something failed badly', $result->matches[0]->parsed?->message ?? '');
    }

    public function test_command_line_search_matches_text_found_only_in_a_stack_frame(): void
    {
        $this->skipWithoutGrep();

        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');

        // grep finds the hit on frame #1, not the header — the searcher must
        // walk backward to find the enclosing header and return ONE record.
        $result = $this->manager('grep')->search($file, new SearchCriteria(query: 'Handler->handle'));

        $this->assertCount(1, $result->matches);
        $this->assertStringStartsWith('Something failed badly', $result->matches[0]->parsed?->message ?? '');
        $this->assertStringContainsString('[stacktrace]', $result->matches[0]->raw);
    }

    public function test_command_line_search_deduplicates_multiple_hits_in_the_same_record(): void
    {
        $this->skipWithoutGrep();

        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');

        // "Handler.php" appears on every one of the 3 frame lines — grep will
        // report 3 separate hits, all inside the same logical record.
        $result = $this->manager('grep')->search($file, new SearchCriteria(query: 'Handler.php'));

        $this->assertCount(1, $result->matches);
    }

    /* ------------------------------------------------------------------ */
    /* RecordAssembler unit-level behaviour                                */
    /* ------------------------------------------------------------------ */

    public function test_record_assembler_locates_the_header_from_a_mid_record_offset(): void
    {
        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');
        $stream = $this->source()->reader($file);
        $size = $this->source()->size($file);

        // Find the byte offset of the "#1 ..." frame line by reading forward.
        $contents = file_get_contents($this->fixture('app.log'));
        $frameOffset = strpos($contents, '#1 /app/vendor');
        $this->assertNotFalse($frameOffset);

        $assembler = new RecordAssembler($this->scanner(), $this->parserManager());
        $record = $assembler->readRecordAt($stream, $frameOffset, $size);

        $this->assertNotNull($record);
        $this->assertStringStartsWith('Something failed badly', $record->parsed?->message ?? '');
        fclose($stream);
    }

    public function test_record_assembler_fold_from_treats_a_non_header_offset_as_an_orphan(): void
    {
        FixtureGenerator::withStackTrace($this->fixture('app.log'), frames: 3);
        $file = $this->logFile('app.log');
        $stream = $this->source()->reader($file);
        $size = $this->source()->size($file);

        $contents = file_get_contents($this->fixture('app.log'));
        $frameOffset = strpos($contents, '#1 /app/vendor');

        $assembler = new RecordAssembler($this->scanner(), $this->parserManager());
        $record = $assembler->foldFrom($stream, $frameOffset, $size);

        // foldFrom() never looks backward, so a mid-record offset is treated
        // as the start of a new (orphan) record — this is what guarantees
        // StreamSearcher always makes forward progress.
        $this->assertNotNull($record);
        $this->assertSame('plain', $record->parsed?->parser);
        $this->assertStringStartsWith('#1 ', $record->raw);
        fclose($stream);
    }

    /* ------------------------------------------------------------------ */
    /* Ordinary single-line entries are unaffected                         */
    /* ------------------------------------------------------------------ */

    public function test_single_line_entries_are_not_folded_together(): void
    {
        FixtureGenerator::laravelLines($this->fixture('plain.log'), 5);
        $file = $this->logFile('plain.log');

        $result = $this->manager('stream')->search($file, new SearchCriteria(query: '', level: null));

        $this->assertCount(5, $result->matches);
        foreach ($result->matches as $match) {
            $this->assertSame([], $match->parsed?->extra);
        }
    }
}

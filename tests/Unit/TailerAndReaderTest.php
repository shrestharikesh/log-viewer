<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Vendor\LogExplorer\Reading\FileReader;
use Vendor\LogExplorer\Reading\Tailer;
use Vendor\LogExplorer\Support\ByteCursor;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

final class TailerAndReaderTest extends TestCase
{
    public function test_tail_returns_last_n_lines_in_order(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 1000);
        $file = $this->logFile('app.log');

        $tailer = new Tailer($this->source(), $this->scanner(), $this->parserManager());
        $result = $tailer->tail($file, 10);

        $this->assertCount(10, $result->lines);
        $this->assertStringContainsString('Message number 990', $result->lines[0]->raw);
        $this->assertStringContainsString('Message number 999', $result->lines[9]->raw);
        $this->assertTrue($result->reachedEnd);
        $this->assertNotNull($result->prevCursor);
    }

    public function test_forward_pagination_walks_the_file_via_cursors(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 50);
        $file = $this->logFile('app.log');
        $reader = new FileReader($this->source(), $this->scanner(), $this->parserManager());

        $page1 = $reader->page($file, null, 20, 'forward');
        $this->assertCount(20, $page1->lines);
        $this->assertStringContainsString('Message number 0', $page1->lines[0]->raw);
        $this->assertNotNull($page1->nextCursor);

        $page2 = $reader->page($file, $page1->nextCursor, 20, 'forward');
        $this->assertStringContainsString('Message number 20', $page2->lines[0]->raw);

        $page3 = $reader->page($file, $page2->nextCursor, 20, 'forward');
        $this->assertCount(10, $page3->lines); // 50 total
        $this->assertTrue($page3->reachedEnd);
        $this->assertNull($page3->nextCursor);
    }

    public function test_previous_direction_returns_preceding_page(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 50);
        $file = $this->logFile('app.log');
        $reader = new FileReader($this->source(), $this->scanner(), $this->parserManager());

        $page1 = $reader->page($file, null, 20, 'forward');     // lines 0..19
        $page2 = $reader->page($file, $page1->nextCursor, 20);  // lines 20..39

        // Going "previous" from page2's start cursor returns lines 0..19 again.
        $prev = $reader->page($file, $page2->prevCursor, 20, 'previous');
        $this->assertStringContainsString('Message number 0', $prev->lines[0]->raw);
        $this->assertStringContainsString('Message number 19', $prev->lines[19]->raw);
    }

    public function test_stale_cursor_against_truncated_file_resets_gracefully(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 50);
        $file = $this->logFile('app.log');
        $reader = new FileReader($this->source(), $this->scanner(), $this->parserManager());

        // Forge a cursor with a bogus fingerprint pointing past a shrunken file.
        $cursor = (new ByteCursor(999999, 'deadbeef'))->encode();
        $page = $reader->page($file, $cursor, 10, 'forward');

        // Instead of erroring, it falls back to reading from the start.
        $this->assertStringContainsString('Message number 0', $page->lines[0]->raw);
    }

    public function test_empty_file_tails_to_nothing(): void
    {
        touch($this->fixture('empty.log'));
        $file = $this->logFile('empty.log');
        $tailer = new Tailer($this->source(), $this->scanner(), $this->parserManager());

        $result = $tailer->tail($file, 100);
        $this->assertCount(0, $result->lines);
    }

    public function test_multiline_stack_trace_folds_into_one_record(): void
    {
        $content = "[2026-01-01 10:00:00] testing.ERROR: Boom\n"
            ."#0 /app/foo.php(10): bar()\n"
            ."#1 /app/baz.php(20): qux()\n"
            ."[2026-01-01 10:00:01] testing.INFO: Next\n";
        file_put_contents($this->fixture('trace.log'), $content);
        $file = $this->logFile('trace.log');

        $reader = new FileReader($this->source(), $this->scanner(), $this->parserManager());
        $page = $reader->page($file, null, 100);

        $this->assertCount(2, $page->lines); // trace folded into the ERROR record
        $this->assertSame('error', $page->lines[0]->parsed->level);
        $this->assertCount(2, $page->lines[0]->parsed->extra);
        $this->assertSame('info', $page->lines[1]->parsed->level);
    }
}

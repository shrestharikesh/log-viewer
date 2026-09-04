<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Vendor\LogExplorer\Tests\TestCase;

final class LineScannerTest extends TestCase
{
    private function write(string $name, string $content): string
    {
        file_put_contents($this->fixture($name), $content);

        return $this->fixture($name);
    }

    public function test_reads_forward_with_exact_offsets(): void
    {
        $path = $this->write('a.log', "alpha\nbeta\ngamma\n");
        $stream = fopen($path, 'rb');
        $size = filesize($path);

        $result = $this->scanner()->readForward($stream, 0, 2, $size);
        fclose($stream);

        $this->assertCount(2, $result['lines']);
        $this->assertSame('alpha', $result['lines'][0]->raw);
        $this->assertSame(0, $result['lines'][0]->offset);
        $this->assertSame(6, $result['lines'][0]->endOffset); // "alpha\n" = 6 bytes
        $this->assertSame('beta', $result['lines'][1]->raw);
        $this->assertSame(6, $result['lines'][1]->offset);
        $this->assertFalse($result['reachedEnd']);
    }

    public function test_tail_offset_with_trailing_newline(): void
    {
        $path = $this->write('b.log', "a\nb\nc\n");
        $stream = fopen($path, 'rb');
        $size = filesize($path);

        // Last 2 lines should start at offset 2 ("b\nc\n").
        $this->assertSame(2, $this->scanner()->findTailOffset($stream, $size, 2));
        // Asking for more lines than exist returns 0 (whole file).
        $this->assertSame(0, $this->scanner()->findTailOffset($stream, $size, 99));
        fclose($stream);
    }

    public function test_tail_offset_without_trailing_newline(): void
    {
        $path = $this->write('c.log', "a\nb\nc"); // no final newline
        $stream = fopen($path, 'rb');
        $size = filesize($path);

        $this->assertSame(2, $this->scanner()->findTailOffset($stream, $size, 2)); // "b\nc"
        fclose($stream);
    }

    public function test_find_offset_before_locates_previous_page_boundary(): void
    {
        $path = $this->write('d.log', "1\n2\n3\n4\n5\n");
        $stream = fopen($path, 'rb');

        // endOffset 6 == start of line "4". The 2 lines before it are "2","3"
        // starting at offset 2.
        $this->assertSame(2, $this->scanner()->findOffsetBefore($stream, 6, 2));
        $this->assertSame(0, $this->scanner()->findOffsetBefore($stream, 6, 99));
        fclose($stream);
    }

    public function test_overlong_line_is_truncated_but_offset_stays_exact(): void
    {
        $scanner = new \Vendor\LogExplorer\Reading\LineScanner(chunkSize: 16, maxLineLength: 8);
        $long = str_repeat('x', 100);
        $path = $this->write('e.log', $long."\nshort\n");

        $stream = fopen($path, 'rb');
        $first = $scanner->readLine($stream);
        fclose($stream);

        $this->assertTrue($first['truncated']);
        $this->assertSame(8, strlen($first['content']));      // capped content
        $this->assertSame(101, $first['consumed']);           // 100 + newline (exact)
    }

    public function test_chunk_boundary_crossing_in_reverse_scan(): void
    {
        // Many short lines so newlines span multiple 64-byte chunks.
        $content = '';
        for ($i = 0; $i < 500; $i++) {
            $content .= "line{$i}\n";
        }
        $path = $this->write('f.log', $content);
        $stream = fopen($path, 'rb');
        $size = filesize($path);

        $start = $this->scanner()->findTailOffset($stream, $size, 3);
        $result = $this->scanner()->readForward($stream, $start, 3, $size);
        fclose($stream);

        $this->assertSame(['line497', 'line498', 'line499'], array_map(fn ($l) => $l->raw, $result['lines']));
    }
}

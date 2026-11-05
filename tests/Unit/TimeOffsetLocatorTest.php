<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Vendor\LogExplorer\Reading\TimeOffsetLocator;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * Binary search from a timestamp to a record-header byte offset.
 */
final class TimeOffsetLocatorTest extends TestCase
{
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = (int) strtotime('2026-03-01 00:00:00');
    }

    /** One record per minute, i => t0 + i minutes. */
    private function minutes(int $count): array
    {
        return array_map(fn (int $i) => $this->t0 + $i * 60, range(0, $count - 1));
    }

    /** @return array{0:TimeOffsetLocator,1:resource,2:int} */
    private function open(string $name, int $probeBytes = 1048576): array
    {
        $path = $this->fixture($name);

        return [
            new TimeOffsetLocator($this->scanner(), $this->parserManager(), $probeBytes),
            fopen($path, 'rb'),
            (int) filesize($path),
        ];
    }

    public function test_exact_boundary_lands_on_the_first_record_at_that_time(): void
    {
        $offsets = FixtureGenerator::timed($this->fixture('t.log'), $this->minutes(200));
        [$locator, $stream, $size] = $this->open('t.log');

        $this->assertSame($offsets[30], $locator->offsetFor($stream, $size, $this->t0 + 30 * 60));
        // One second later, record 30 is too old: start at record 31.
        $this->assertSame($offsets[31], $locator->offsetFor($stream, $size, $this->t0 + 30 * 60 + 1));
        // The exclusive end for "until record 30's time" is record 31.
        $this->assertSame($offsets[31], $locator->endOffsetFor($stream, $size, $this->t0 + 30 * 60));
        $this->assertSame($offsets[31], $locator->endOffsetFor($stream, $size, $this->t0 + 30 * 60 + 59));
    }

    public function test_every_boundary_matches_a_linear_scan(): void
    {
        $offsets = FixtureGenerator::timed($this->fixture('t.log'), $this->minutes(300), traceEvery: 4);
        [$locator, $stream, $size] = $this->open('t.log');

        for ($i = 0; $i < 300; $i += 7) {
            $this->assertSame($offsets[$i], $locator->offsetFor($stream, $size, $this->t0 + $i * 60), "start for record {$i}");
            $expectedEnd = $offsets[$i + 1] ?? $size;
            $this->assertSame($expectedEnd, $locator->endOffsetFor($stream, $size, $this->t0 + $i * 60), "end for record {$i}");
        }
    }

    public function test_target_before_the_first_entry(): void
    {
        FixtureGenerator::timed($this->fixture('t.log'), $this->minutes(50));
        [$locator, $stream, $size] = $this->open('t.log');

        $this->assertSame(0, $locator->offsetFor($stream, $size, $this->t0 - 86400));
        // Nothing is at or before a time earlier than the first record.
        $this->assertSame(0, $locator->endOffsetFor($stream, $size, $this->t0 - 86400));
    }

    public function test_target_after_the_last_entry(): void
    {
        FixtureGenerator::timed($this->fixture('t.log'), $this->minutes(50));
        [$locator, $stream, $size] = $this->open('t.log');

        $this->assertSame($size, $locator->offsetFor($stream, $size, $this->t0 + 86400));
        $this->assertSame($size, $locator->endOffsetFor($stream, $size, $this->t0 + 86400));
    }

    public function test_offsets_are_record_headers_even_when_probes_land_in_stack_traces(): void
    {
        // Every record has a trace, so most probe positions land inside one.
        $offsets = FixtureGenerator::timed($this->fixture('t.log'), $this->minutes(120), traceEvery: 1);
        [$locator, $stream, $size] = $this->open('t.log');

        $headers = array_flip($offsets);
        for ($minute = -1; $minute <= 121; $minute += 5) {
            $start = $locator->offsetFor($stream, $size, $this->t0 + $minute * 60 + 13);
            $end = $locator->endOffsetFor($stream, $size, $this->t0 + $minute * 60 + 13);

            $this->assertTrue($start === $size || isset($headers[$start]), "start {$start} is not a header");
            $this->assertTrue($end === $size || isset($headers[$end]), "end {$end} is not a header");
        }
    }

    public function test_a_file_without_timestamps_gets_the_full_range(): void
    {
        file_put_contents($this->fixture('plain.log'), str_repeat("no timestamp here, just text\n", 500));
        [$locator, $stream, $size] = $this->open('plain.log');

        $this->assertSame(0, $locator->offsetFor($stream, $size, $this->t0));
        $this->assertSame($size, $locator->endOffsetFor($stream, $size, $this->t0));
    }

    public function test_a_header_too_far_away_to_probe_widens_the_range_instead_of_narrowing_it(): void
    {
        // A small probe window: each probe below sees no header at all, so
        // every decision is "unknown" and must fall to the safe side.
        $h = fopen($this->fixture('gap.log'), 'wb');
        fwrite($h, '['.date('Y-m-d H:i:s', $this->t0)."] testing.INFO: first\n");
        fwrite($h, str_repeat("    continuation line without a header\n", 2000));
        fwrite($h, '['.date('Y-m-d H:i:s', $this->t0 + 3600)."] testing.INFO: last\n");
        fclose($h);

        [$locator, $stream, $size] = $this->open('gap.log', probeBytes: 256);

        $start = $locator->offsetFor($stream, $size, $this->t0 + 1800);
        $end = $locator->endOffsetFor($stream, $size, $this->t0 + 1800);

        // The exact answer for both is the last record's header. The
        // locator may err wide (start earlier, end later), never narrow.
        $lastHeader = strrpos((string) file_get_contents($this->fixture('gap.log')), "\n[") + 1;
        $this->assertLessThanOrEqual($lastHeader, $start);
        $this->assertGreaterThanOrEqual($lastHeader, $end);

        // "Until the last record's time" must reach the end of the file.
        $this->assertSame($size, $locator->endOffsetFor($stream, $size, $this->t0 + 3600));
    }

    public function test_json_logs_with_a_datetime_field_are_seekable(): void
    {
        $h = fopen($this->fixture('j.log'), 'wb');
        $offsets = [];
        $bytes = 0;
        for ($i = 0; $i < 100; $i++) {
            $offsets[$i] = $bytes;
            $bytes += fwrite($h, json_encode([
                'datetime' => date('c', $this->t0 + $i * 60),
                'level' => 'info',
                'message' => "json {$i}",
            ])."\n");
        }
        fclose($h);

        [$locator, $stream, $size] = $this->open('j.log');

        $this->assertSame($offsets[42], $locator->offsetFor($stream, $size, $this->t0 + 42 * 60));
        $this->assertSame($offsets[43], $locator->endOffsetFor($stream, $size, $this->t0 + 42 * 60));
    }

    public function test_an_empty_file(): void
    {
        touch($this->fixture('empty.log'));
        [$locator, $stream, $size] = $this->open('empty.log');

        $this->assertSame(0, $locator->offsetFor($stream, $size, $this->t0));
        $this->assertSame(0, $locator->endOffsetFor($stream, $size, $this->t0));
    }
}

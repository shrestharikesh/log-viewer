<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\LogExplorer\Parsing\JsonLogParser;
use Vendor\LogExplorer\Parsing\LaravelLogParser;

final class ParserTest extends TestCase
{
    public function test_laravel_parser_extracts_fields_and_context(): void
    {
        $parser = new LaravelLogParser();
        $line = '[2026-01-01 10:00:00] production.ERROR: Payment failed {"order_id":99}';

        $this->assertTrue($parser->supports($line));
        $parsed = $parser->parse($line);

        $this->assertSame('error', $parsed->level);
        $this->assertSame('production', $parsed->channel);
        $this->assertSame('2026-01-01 10:00:00', $parsed->datetime);
        $this->assertSame('Payment failed', $parsed->message);
        $this->assertSame(['order_id' => 99], $parsed->context);
    }

    public function test_laravel_parser_handles_microseconds_and_timezone(): void
    {
        $parser = new LaravelLogParser();
        $this->assertTrue($parser->supports('[2026-01-01 10:00:00.123456+00:00] local.DEBUG: hi'));
    }

    public function test_laravel_parser_rejects_non_matching_lines(): void
    {
        $parser = new LaravelLogParser();
        $this->assertFalse($parser->supports('#0 /app/foo.php(10): bar()'));
        $this->assertFalse($parser->supports('plain text line'));
    }

    public function test_json_parser_extracts_known_keys(): void
    {
        $parser = new JsonLogParser();
        $line = '{"level":"error","message":"Failed","datetime":"2026-01-01T10:00:00Z","context":{"a":1}}';

        $this->assertTrue($parser->supports($line));
        $parsed = $parser->parse($line);

        $this->assertSame('error', $parsed->level);
        $this->assertSame('Failed', $parsed->message);
        $this->assertSame(['a' => 1], $parsed->context);
    }

    public function test_json_parser_rejects_non_json(): void
    {
        $parser = new JsonLogParser();
        $this->assertFalse($parser->supports('[2026-01-01 10:00:00] local.INFO: hi'));
        $this->assertFalse($parser->supports('{not json}'));
    }
}

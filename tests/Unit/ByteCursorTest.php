<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\LogExplorer\Support\ByteCursor;

final class ByteCursorTest extends TestCase
{
    public function test_round_trips_offset_and_fingerprint(): void
    {
        // The size must be >= the offset: validFor() also enforces that the
        // cursor points inside the file, so fingerprinting a 1000-byte file
        // while pointing at byte 12345 would (correctly) be invalid.
        $cursor = new ByteCursor(12345, ByteCursor::fingerprint(20000, 1700000000));
        $decoded = ByteCursor::decode($cursor->encode());

        $this->assertSame(12345, $decoded->offset);
        $this->assertTrue($decoded->validFor(20000, 1700000000));
    }

    public function test_bare_integer_is_accepted(): void
    {
        $this->assertSame(42, ByteCursor::decode('42')->offset);
    }

    public function test_invalid_cursor_returns_null(): void
    {
        $this->assertNull(ByteCursor::decode('!!!not-base64!!!@@'));
        $this->assertNull(ByteCursor::decode(null));
        $this->assertNull(ByteCursor::decode(''));
    }

    public function test_fingerprint_mismatch_invalidates_cursor(): void
    {
        $cursor = new ByteCursor(10, ByteCursor::fingerprint(1000, 1700000000));

        $this->assertFalse($cursor->validFor(2000, 1700000000)); // size changed
        $this->assertFalse($cursor->validFor(1000, 1700000001)); // mtime changed
    }

    public function test_offset_beyond_size_is_invalid(): void
    {
        $cursor = new ByteCursor(5000);
        $this->assertFalse($cursor->validFor(100, 1));
        $this->assertTrue((new ByteCursor(50))->validFor(100, 1));
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Support;

/**
 * Opaque cursor for byte-offset pagination.
 *
 * Cursors intentionally encode a byte OFFSET (always a line boundary, by the
 * reader's invariant) rather than a line number. Byte offsets are O(1) to seek
 * to with fseek() regardless of file size, whereas "page 9000 by line number"
 * would force a full scan. The cursor also carries a short file fingerprint
 * (size + mtime) so a stale cursor against a rotated/truncated file can be
 * detected and reset instead of returning garbage.
 */
final class ByteCursor
{
    public function __construct(
        public readonly int $offset,
        public readonly ?string $fingerprint = null,
    ) {
    }

    public function encode(): string
    {
        $payload = $this->fingerprint === null
            ? (string) $this->offset
            : $this->offset.'.'.$this->fingerprint;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?self
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        // Allow a bare integer offset for convenience / hand-crafted requests.
        if (ctype_digit($cursor)) {
            return new self((int) $cursor);
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        if (str_contains($decoded, '.')) {
            [$offset, $fingerprint] = explode('.', $decoded, 2);

            return ctype_digit($offset) ? new self((int) $offset, $fingerprint) : null;
        }

        return ctype_digit($decoded) ? new self((int) $decoded) : null;
    }

    public static function fingerprint(int $size, int $mtime): string
    {
        return substr(hash('xxh3', $size.':'.$mtime), 0, 8);
    }

    /**
     * A cursor is valid for a file only if it points within the file and its
     * fingerprint (when present) still matches. Rotated/truncated files yield
     * false so the caller can gracefully reset to the start/end.
     */
    public function validFor(int $size, int $mtime): bool
    {
        if ($this->offset < 0 || $this->offset > $size) {
            return false;
        }

        if ($this->fingerprint === null) {
            return true;
        }

        return hash_equals(self::fingerprint($size, $mtime), $this->fingerprint);
    }
}

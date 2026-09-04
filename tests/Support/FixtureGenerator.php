<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Support;

/**
 * Generates log fixtures by STREAMING writes (fwrite in a loop) so even a 50 GB
 * fixture is created with a flat memory profile — mirroring how the package
 * itself must read. Never builds the whole file in a string first.
 */
final class FixtureGenerator
{
    public static function laravelLines(string $path, int $count, int $startIndex = 0): int
    {
        $levels = ['INFO', 'DEBUG', 'WARNING', 'ERROR', 'CRITICAL'];
        $h = fopen($path, 'wb');
        $bytes = 0;

        for ($i = $startIndex; $i < $startIndex + $count; $i++) {
            $level = $levels[$i % count($levels)];
            $line = sprintf(
                "[2026-01-%02d 10:%02d:%02d] testing.%s: Message number %d {\"i\":%d}\n",
                ($i % 28) + 1, $i % 60, $i % 60, $level, $i, $i,
            );
            $bytes += fwrite($h, $line);
        }

        fclose($h);

        return $bytes;
    }

    public static function jsonLines(string $path, int $count): int
    {
        $levels = ['info', 'debug', 'warning', 'error'];
        $h = fopen($path, 'wb');
        $bytes = 0;

        for ($i = 0; $i < $count; $i++) {
            $line = json_encode([
                'level' => $levels[$i % count($levels)],
                'datetime' => sprintf('2026-01-01T10:00:%02d+00:00', $i % 60),
                'channel' => 'testing',
                'message' => "json message {$i}",
                'context' => ['i' => $i],
            ])."\n";
            $bytes += fwrite($h, $line);
        }

        fclose($h);

        return $bytes;
    }

    /**
     * Write a file of at least $targetBytes using identifiable, fixed-format
     * lines. Returns [bytesWritten, lineCount].
     *
     * @return array{0:int,1:int}
     */
    public static function ofSize(string $path, int $targetBytes): array
    {
        $h = fopen($path, 'wb');
        $bytes = 0;
        $i = 0;

        while ($bytes < $targetBytes) {
            $line = sprintf("[2026-01-01 10:00:00] testing.INFO: filler line %010d padding-padding-padding\n", $i);
            $bytes += fwrite($h, $line);
            $i++;
        }

        fclose($h);

        return [$bytes, $i];
    }
}

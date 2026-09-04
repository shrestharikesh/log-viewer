<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Parsing;

use Vendor\LogExplorer\Contracts\LogParserInterface;

/**
 * Parses the default Laravel/Monolog line format:
 *
 *   [2026-01-01 10:00:00] production.ERROR: Something failed {"context":1}
 *   [2026-01-01 10:00:00.123456+00:00] local.DEBUG: ...
 *
 * Only the header line is matched here; indented stack-trace / context lines
 * are recognised as continuations by the ParserManager (they fail supports()).
 */
final class LaravelLogParser implements LogParserInterface
{
    private const HEADER = '/^\[(?<datetime>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+(?<channel>[\w\-.]+)\.(?<level>[A-Z]+):\s?(?<message>.*)$/s';

    public function name(): string
    {
        return 'laravel';
    }

    public function supports(string $line): bool
    {
        // Fast reject before the regex: must start with "[YYYY-".
        return isset($line[5])
            && $line[0] === '['
            && ctype_digit($line[1].$line[2].$line[3].$line[4])
            && preg_match(self::HEADER, $line) === 1;
    }

    public function parse(string $line): ParsedLine
    {
        preg_match(self::HEADER, $line, $m);

        $message = $m['message'] ?? $line;
        $context = [];

        // Pull a trailing JSON context object/array off the message if present.
        $trimmed = rtrim($message);
        if ($trimmed !== '' && ($trimmed[-1] === '}' || $trimmed[-1] === ']')) {
            $brace = strcspn($trimmed, '{[');
            if ($brace < strlen($trimmed)) {
                $candidate = substr($trimmed, $brace);
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    $context = $decoded;
                    $message = rtrim(substr($trimmed, 0, $brace));
                }
            }
        }

        return new ParsedLine(
            parser: 'laravel',
            level: strtolower($m['level'] ?? ''),
            datetime: $m['datetime'] ?? null,
            channel: $m['channel'] ?? null,
            message: $message,
            context: $context,
        );
    }
}

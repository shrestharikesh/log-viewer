<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Parsing;

use Vendor\LogExplorer\Contracts\LogParserInterface;

/**
 * Parses newline-delimited JSON logs (one JSON object per line), the common
 * output of Monolog's JsonFormatter and most structured loggers:
 *
 *   {"level":"error","message":"Failed","datetime":"...","context":{...}}
 */
final class JsonLogParser implements LogParserInterface
{
    public function name(): string
    {
        return 'json';
    }

    public function supports(string $line): bool
    {
        $line = ltrim($line);

        // Must look like a JSON object and actually decode to an array.
        return isset($line[0])
            && $line[0] === '{'
            && json_decode($line, true) !== null
            && json_last_error() === JSON_ERROR_NONE;
    }

    public function parse(string $line): ParsedLine
    {
        $data = json_decode(trim($line), true) ?: [];

        $level = $data['level'] ?? $data['level_name'] ?? $data['severity'] ?? null;
        $datetime = $data['datetime'] ?? $data['time'] ?? $data['timestamp'] ?? $data['@timestamp'] ?? null;
        $channel = $data['channel'] ?? $data['logger'] ?? null;
        $message = $data['message'] ?? $data['msg'] ?? null;

        // Whatever is left after the well-known keys is the context.
        $context = $data['context'] ?? array_diff_key($data, array_flip([
            'level', 'level_name', 'severity', 'datetime', 'time', 'timestamp',
            '@timestamp', 'channel', 'logger', 'message', 'msg',
        ]));

        return new ParsedLine(
            parser: 'json',
            level: is_string($level) ? strtolower($level) : (is_int($level) ? (string) $level : null),
            datetime: is_string($datetime) ? $datetime : null,
            channel: is_string($channel) ? $channel : null,
            message: is_string($message) ? $message : json_encode($message),
            context: is_array($context) ? $context : ['value' => $context],
        );
    }
}

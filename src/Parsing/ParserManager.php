<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Parsing;

use Vendor\LogExplorer\Contracts\LogParserInterface;
use Vendor\LogExplorer\Support\LogLine;

/**
 * Resolves the right parser per line and stitches multi-line records together.
 *
 * For each line the first parser whose supports() returns true "owns" it and
 * becomes the current record header. Subsequent lines that no parser claims are
 * treated as continuations (stack traces, indented context) and folded into the
 * preceding record's `extra`, so a 30-frame exception renders as one entry.
 */
final class ParserManager
{
    /** @var array<string, LogParserInterface> */
    private array $parsers = [];

    private bool $fallbackToPlain = true;

    /**
     * @param  array<string, LogParserInterface>  $parsers  ordered by priority
     */
    public function __construct(array $parsers = [], bool $fallbackToPlain = true)
    {
        foreach ($parsers as $name => $parser) {
            $this->register(is_string($name) ? $name : $parser->name(), $parser);
        }
        $this->fallbackToPlain = $fallbackToPlain;
    }

    public function register(string $name, LogParserInterface $parser): void
    {
        // Re-registering a key overrides the built-in, preserving order.
        $this->parsers[$name] = $parser;
    }

    public function first(string $line): ?LogParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($line)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Parse a batch of raw lines into structured LogLines, merging continuation
     * lines into the most recent header record.
     *
     * @param  LogLine[]  $lines
     * @return LogLine[]
     */
    public function parseLines(array $lines): array
    {
        $out = [];
        $lastHeaderIndex = null;

        foreach ($lines as $line) {
            $parser = $this->first($line->raw);

            if ($parser !== null) {
                $parsed = $parser->parse($line->raw);
                $out[] = new LogLine(
                    $line->offset,
                    $line->endOffset,
                    $line->raw,
                    $line->truncated,
                    $parsed,
                );
                $lastHeaderIndex = array_key_last($out);

                continue;
            }

            // Continuation: attach to the previous header if there is one.
            if ($lastHeaderIndex !== null && $out[$lastHeaderIndex]->parsed !== null) {
                $prev = $out[$lastHeaderIndex];
                $out[$lastHeaderIndex] = new LogLine(
                    $prev->offset,
                    $line->endOffset, // extend the record's byte range
                    $prev->raw."\n".$line->raw,
                    $prev->truncated || $line->truncated,
                    $prev->parsed->withExtra($line->raw),
                );

                continue;
            }

            // Orphan line with no header above it.
            $out[] = new LogLine(
                $line->offset,
                $line->endOffset,
                $line->raw,
                $line->truncated,
                $this->fallbackToPlain ? ParsedLine::plain($line->raw) : null,
            );
            $lastHeaderIndex = array_key_last($out);
        }

        return $out;
    }
}

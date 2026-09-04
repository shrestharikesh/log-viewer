<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Contracts;

use Vendor\LogExplorer\Parsing\ParsedLine;

/**
 * Parses a single raw log line into a structured ParsedLine.
 *
 * Parsers operate one line at a time so they compose with the streaming reader
 * and never need the whole file. Multi-line records (e.g. PHP stack traces) are
 * handled by the ParserManager, which appends "continuation" lines to the most
 * recent record that began with a header line (see ParsedLine::$isHeader).
 */
interface LogParserInterface
{
    /**
     * Cheap predicate: does this parser recognise the line as the START of a
     * record it owns? Should be fast (prefix / regex anchor checks), never
     * throw, and never allocate large structures.
     */
    public function supports(string $line): bool;

    /**
     * Parse a header line into structured fields. Only called when
     * supports() returned true.
     */
    public function parse(string $line): ParsedLine;

    /**
     * Stable identifier, e.g. "laravel", "json". Used for UI hints and
     * to let apps override a built-in parser by re-registering the key.
     */
    public function name(): string;
}

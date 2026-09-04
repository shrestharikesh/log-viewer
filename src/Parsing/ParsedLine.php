<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Parsing;

/**
 * Structured view of a log record header. Continuation lines (stack traces,
 * pretty-printed context) are appended to `extra` by the ParserManager.
 */
final class ParsedLine implements \JsonSerializable
{
    /**
     * @param  array<int,string>  $extra  continuation/overflow lines
     * @param  array<string,mixed>  $context  decoded structured context (JSON logs)
     */
    public function __construct(
        public readonly string $parser,
        public readonly ?string $level = null,
        public readonly ?string $datetime = null,
        public readonly ?string $channel = null,
        public readonly ?string $message = null,
        public readonly array $context = [],
        public readonly array $extra = [],
        public readonly bool $isHeader = true,
    ) {
    }

    public function withExtra(string $line): self
    {
        return new self(
            $this->parser,
            $this->level,
            $this->datetime,
            $this->channel,
            $this->message,
            $this->context,
            [...$this->extra, $line],
            $this->isHeader,
        );
    }

    public static function plain(string $line): self
    {
        return new self('plain', message: $line, isHeader: true);
    }

    public function jsonSerialize(): array
    {
        return [
            'parser' => $this->parser,
            'level' => $this->level,
            'datetime' => $this->datetime,
            'channel' => $this->channel,
            'message' => $this->message,
            'context' => $this->context,
            'extra' => $this->extra,
        ];
    }
}

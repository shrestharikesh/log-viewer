<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Contracts;

use Vendor\LogExplorer\Support\LogFile;

/**
 * A LogSource abstracts WHERE log files live and how raw bytes are obtained.
 *
 * The reading engine (Tailer, FileReader, searchers, streamer) is written
 * entirely against this interface and the PHP stream it returns, so adding a
 * remote provider (SSH, S3, syslog) is a matter of implementing this contract
 * and registering it via LogExplorer::extendSource().
 *
 * Implementations MUST NOT read whole files into memory. The `reader()` method
 * returns a seekable stream resource so callers can fseek()/fread() arbitrary
 * byte ranges. Remote drivers that cannot offer true seeking should expose a
 * locally-cached stream or proxy seeks over the wire.
 */
interface LogSourceInterface
{
    /**
     * The driver key, e.g. "local", "ssh", "s3".
     */
    public function name(): string;

    /**
     * List discoverable log files (non-recursive per root unless implemented).
     *
     * @return LogFile[]
     */
    public function files(): array;

    /**
     * Resolve an opaque, client-supplied file identifier to a validated
     * LogFile, or throw if it is unknown / disallowed / outside a source root.
     *
     * @throws \Vendor\LogExplorer\Exceptions\InvalidLogFileException
     */
    public function resolve(string $identifier): LogFile;

    /**
     * Whether the underlying file currently exists and is readable.
     */
    public function exists(LogFile $file): bool;

    /**
     * Size of the file in bytes (must not read the file).
     */
    public function size(LogFile $file): int;

    /**
     * Unix mtime of the file.
     */
    public function lastModified(LogFile $file): int;

    /**
     * Open a SEEKABLE, binary read stream positioned at byte 0.
     *
     * Callers own the resource and must fclose() it. The stream MUST support
     * fseek() with SEEK_SET/SEEK_END for the reading engine to work.
     *
     * @return resource
     */
    public function reader(LogFile $file);

    /**
     * Absolute, canonical path on a filesystem the host can shell out to,
     * or null when the source is not addressable by a local CLI tool.
     *
     * Returning a path here is what allows ripgrep/grep acceleration. Remote
     * sources return null and transparently fall back to streaming search.
     */
    public function localPath(LogFile $file): ?string;
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Sources;

use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Exceptions\InvalidLogFileException;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\PathValidator;

/**
 * Reads log files from the local filesystem.
 *
 * Listing is recursive within each configured root; every resolved file is run
 * through PathValidator so traversal ("../../etc/passwd"), disallowed
 * extensions, and hidden patterns are rejected before any byte is read.
 */
final class LocalLogSource implements LogSourceInterface
{
    /**
     * @param  string[]  $roots
     */
    public function __construct(
        private readonly array $roots,
        private readonly PathValidator $validator,
    ) {
    }

    public function name(): string
    {
        return 'local';
    }

    public function files(): array
    {
        $files = [];

        foreach ($this->roots as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false || ! is_dir($realRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $info */
            foreach ($iterator as $info) {
                $path = $info->getPathname();
                if ($this->validator->accessible($path) === null) {
                    continue;
                }

                $relative = ltrim(substr($path, strlen($realRoot)), DIRECTORY_SEPARATOR);
                $files[] = new LogFile(
                    driver: 'local',
                    identifier: LogFile::makeIdentifier('local', $path),
                    relativePath: $relative,
                    path: $path,
                    name: $info->getFilename(),
                );
            }
        }

        // Most-recently-modified first — the logs people actually want.
        usort($files, fn (LogFile $a, LogFile $b) => $this->lastModified($b) <=> $this->lastModified($a));

        return $files;
    }

    public function resolve(string $identifier): LogFile
    {
        $parsed = LogFile::parseIdentifier($identifier);
        if ($parsed === null || $parsed['driver'] !== 'local') {
            throw InvalidLogFileException::notFound($identifier);
        }

        // parseIdentifier stored the absolute path as the "relativePath" slot
        // for local files; re-validate it against the roots from scratch.
        $real = $this->validator->accessible($parsed['relativePath']);
        if ($real === null) {
            throw InvalidLogFileException::outsideRoot($identifier);
        }

        return new LogFile(
            driver: 'local',
            identifier: $identifier,
            relativePath: $real,
            path: $real,
            name: basename($real),
        );
    }

    public function exists(LogFile $file): bool
    {
        return $file->path !== null && is_file($file->path) && is_readable($file->path);
    }

    public function size(LogFile $file): int
    {
        $this->guard($file);

        clearstatcache(true, $file->path);

        return (int) filesize($file->path);
    }

    public function lastModified(LogFile $file): int
    {
        if ($file->path === null || ! is_file($file->path)) {
            return 0;
        }

        return (int) filemtime($file->path);
    }

    public function reader(LogFile $file)
    {
        $this->guard($file);

        $stream = fopen($file->path, 'rb');
        if ($stream === false) {
            throw InvalidLogFileException::notFound($file->identifier);
        }

        return $stream;
    }

    public function localPath(LogFile $file): ?string
    {
        return $this->exists($file) ? $file->path : null;
    }

    private function guard(LogFile $file): void
    {
        if (! $this->exists($file)) {
            throw InvalidLogFileException::notFound($file->identifier);
        }
    }
}

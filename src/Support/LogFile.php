<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Support;

/**
 * Immutable value object describing a single resolved log file.
 *
 * `identifier` is the opaque, traversal-safe token exchanged with the client
 * (a base64url of "{driver}:{relative-path}"). `path` is the canonical absolute
 * path for local drivers and may be null for remote drivers.
 */
final class LogFile
{
    public function __construct(
        public readonly string $driver,
        public readonly string $identifier,
        public readonly string $relativePath,
        public readonly ?string $path,
        public readonly string $name,
    ) {
    }

    public static function makeIdentifier(string $driver, string $relativePath): string
    {
        return rtrim(strtr(base64_encode($driver.':'.$relativePath), '+/', '-_'), '=');
    }

    /**
     * @return array{driver:string, relativePath:string}|null
     */
    public static function parseIdentifier(string $identifier): ?array
    {
        $decoded = base64_decode(strtr($identifier, '-_', '+/'), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$driver, $relativePath] = explode(':', $decoded, 2);

        if ($driver === '' || $relativePath === '') {
            return null;
        }

        return ['driver' => $driver, 'relativePath' => $relativePath];
    }
}

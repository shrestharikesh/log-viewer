<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Support;

/**
 * Centralises every "is this path safe / allowed?" decision so traversal logic
 * lives in exactly one place and is unit-testable in isolation.
 */
final class PathValidator
{
    /**
     * @param  string[]  $roots             canonical absolute source roots
     * @param  string[]  $allowedExtensions e.g. ['log','txt']
     * @param  string[]  $hiddenPatterns    fnmatch patterns (relative basenames)
     */
    public function __construct(
        private readonly array $roots,
        private readonly array $allowedExtensions,
        private readonly array $hiddenPatterns = [],
        private readonly bool $showHiddenDotfiles = false,
    ) {
    }

    /**
     * Canonicalise a candidate absolute path and confirm it lives inside one
     * of the configured roots. Returns the canonical path, or null if the
     * path escapes every root (traversal) or does not exist.
     *
     * Uses realpath() so that "../", symlinks, and "." segments are resolved
     * before the containment check — this is the primary traversal defence.
     */
    public function within(string $candidate): ?string
    {
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }

        foreach ($this->roots as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false) {
                continue;
            }

            // Containment: equal to the root, or prefixed by "root/".
            if ($real === $realRoot || str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    public function extensionAllowed(string $path): bool
    {
        if ($this->allowedExtensions === []) {
            return true;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, array_map('strtolower', $this->allowedExtensions), true);
    }

    /**
     * True when a file should be hidden from listings / blocked from access.
     */
    public function isHidden(string $path): bool
    {
        $base = basename($path);

        if (! $this->showHiddenDotfiles && str_starts_with($base, '.')) {
            // Dotfiles are hidden unless explicitly enabled, BUT ".env" style
            // sensitive names are always caught by the patterns below too.
            if ($base !== '.' && $base !== '..') {
                return true;
            }
        }

        foreach ($this->hiddenPatterns as $pattern) {
            if (fnmatch($pattern, $base, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path is accessible when it is inside a root, has an allowed extension,
     * and is not hidden.
     */
    public function accessible(string $candidate): ?string
    {
        $real = $this->within($candidate);
        if ($real === null) {
            return null;
        }

        if (! is_file($real) || ! $this->extensionAllowed($real) || $this->isHidden($real)) {
            return null;
        }

        return $real;
    }
}

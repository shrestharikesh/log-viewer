<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http;

use Illuminate\Http\Request;

/**
 * Typed access to query-string parameters.
 *
 * `$request->query('q')` is an array for "?q[]=x", which would otherwise
 * surface as a TypeError (a 500) deep inside a reader or searcher. Every
 * package controller reads strings through here instead, so a malformed
 * parameter behaves as if it were absent.
 */
final class QueryInput
{
    public static function string(Request $request, string $key, ?string $default = null): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : $default;
    }
}

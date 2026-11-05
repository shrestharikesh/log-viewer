<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Support;

/**
 * The one place a log or request datetime string becomes a unix timestamp,
 * so the time seek, the date post-filter and the count buckets always agree.
 * Strings without a zone are read in PHP's default timezone.
 */
final class Timestamp
{
    public static function parse(?string $datetime): ?int
    {
        if ($datetime === null || trim($datetime) === '') {
            return null;
        }

        $ts = strtotime($datetime);

        return $ts === false ? null : $ts;
    }
}

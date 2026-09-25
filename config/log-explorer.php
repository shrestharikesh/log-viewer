<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route Registration
    |--------------------------------------------------------------------------
    |
    | The package registers a small set of JSON-first API routes plus an
    | optional standalone UI route. Set "enabled" to false if you only want
    | to embed the Blade component / widget and wire routes yourself.
    |
    */
    'routes' => [
        'enabled' => true,

        // Prefix for every package route. The standalone UI lives at "{prefix}"
        // and the JSON API lives under "{prefix}/api".
        'prefix' => 'admin/logs',

        // Route name prefix, e.g. "log-explorer.files".
        'as' => 'log-explorer.',

        // Middleware applied to all package routes. Keep this locked down.
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Gates checked before any read / download. Define them in your app's
    | AuthServiceProvider, or set a custom callback below. Secure by default:
    | if the gate is undefined the package DENIES access (see "strict").
    |
    */
    'authorization' => [
        'view_gate' => 'viewLogs',
        'download_gate' => 'downloadLogs',

        // When true, an undefined gate denies access. When false, an undefined
        // gate falls back to "any authenticated user".
        'strict' => true,

        // Optional fully-qualified callable "[Class::class, 'method']" or a
        // closure registered at runtime via LogExplorer::authorizeUsing().
        'callback' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Sources
    |--------------------------------------------------------------------------
    |
    | Directories that may be browsed. Paths are canonicalised and every file
    | request is validated to live inside one of these roots (no traversal).
    |
    */
    'sources' => [

        // The default driver used to resolve files. Extra drivers (ssh, s3...)
        // can be registered via LogExplorer::extendSource().
        'default' => 'local',

        'drivers' => [
            'local' => [
                'driver' => 'local',
                'paths' => [
                    storage_path('logs'),
                    // '/var/log/nginx',
                    // '/var/log/custom-app',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Filtering
    |--------------------------------------------------------------------------
    */
    'files' => [
        'allowed_extensions' => ['log', 'txt'],

        // Glob-style patterns (relative to each source root) that are hidden
        // from listings and rejected on direct access.
        'hidden' => [
            '*.key',
            '*.pem',
            '.env*',
        ],

        // Show dotfiles in directory listings.
        'show_hidden_dotfiles' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading / Pagination
    |--------------------------------------------------------------------------
    |
    | All reads are streamed. These knobs bound per-request work so a single
    | request can never balloon memory regardless of file size.
    |
    */
    'reading' => [
        // Bytes read per low-level chunk during forward/reverse scanning.
        'chunk_size' => 8192,

        // Default number of lines returned per page.
        'page_size' => 200,

        // Hard cap on lines per page (protects against abusive ?limit=).
        'max_page_size' => 5000,

        // Maximum length (bytes) of a single line returned to the client.
        // Longer lines are truncated with an ellipsis marker; the full line
        // remains streamable / downloadable.
        'max_line_length' => 32768,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tail Mode
    |--------------------------------------------------------------------------
    */
    'tail' => [
        'presets' => [100, 500, 1000, 5000],
        'default' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | "auto" prefers ripgrep when available, falls back to grep, then to the
    | pure-PHP streaming searcher. Force a mode with "stream" | "ripgrep" |
    | "grep".
    |
    */
    'search' => [
        'mode' => 'auto',

        'ripgrep' => [
            'binary' => 'rg',
            'enabled' => true,
        ],

        'grep' => [
            'binary' => 'grep',
            'enabled' => true,
        ],

        // Max matches returned per search request (results are streamed).
        'max_results' => 1000,

        // Wall-clock budget for a single search, in seconds.
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Download Protection
    |--------------------------------------------------------------------------
    */
    'download' => [
        'enabled' => true,

        // Files larger than this are blocked outright (a "tail" download or
        // ranged download is offered instead by the UI).
        'max_download_size_mb' => 100,

        // Warn (require ?confirm=1) above this size even when allowed.
        'warn_download_size_mb' => 25,

        // Per-user download throttle, "attempts,decay_minutes".
        'rate_limit' => '10,1',

        // Write an audit log entry for every download attempt.
        'audit' => true,

        // Channel used for audit entries (configured in config/logging.php).
        // null => use the default channel.
        'audit_channel' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Streaming (SSE)
    |--------------------------------------------------------------------------
    */
    'streaming' => [
        'enabled' => true,
        'driver' => 'sse', // 'sse' | 'websockets' (future)

        // Max seconds a single SSE connection stays open before asking the
        // browser to reconnect (keeps PHP-FPM workers from being pinned).
        'max_duration' => 60,

        // Poll interval (ms) used to check the file for newly appended bytes.
        'poll_interval' => 1000,

        // Bytes read per poll tick (bounds memory for very chatty logs).
        'max_bytes_per_tick' => 262144,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    |
    | Parsers are tried in order; the first that "supports()" a line wins.
    | Register your own with LogExplorer::extendParser('name', Parser::class).
    |
    */
    'parsing' => [
        'parsers' => [
            'laravel' => \Vendor\LogExplorer\Parsing\LaravelLogParser::class,
            'json' => \Vendor\LogExplorer\Parsing\JsonLogParser::class,
        ],

        // Treat lines that no parser claims as plain text (vs. dropping them).
        'fallback_to_plain' => true,

        // A multi-line record (a header plus stack-trace / pretty-printed
        // context lines that follow it) is folded into a single match, up to
        // this many continuation lines. Beyond the cap, folding stops and the
        // remainder is read as further record(s) — bounds memory/time against
        // a pathological single log call with no next header for a long time.
        'max_continuation_lines' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'title' => 'Log Explorer',
        'theme' => 'system', // 'light' | 'dark' | 'system'
        'poll_tail' => true,
        // Layout shell for the standalone route. Set to your app layout to
        // embed the viewer inside an existing dashboard chrome.
        'layout' => null,
    ],
];

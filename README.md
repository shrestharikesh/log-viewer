# Laravel Log Explorer

A high-performance, memory-safe log viewer for Laravel. It streams log files of
**any size — 10 GB, 50 GB, 100 GB+ — in a flat ~few-MB memory footprint**, and
drops into any existing admin dashboard (Filament, Nova, Tailwind, Bootstrap,
custom) as a route, a Blade component, or an embedded widget.

```bash
composer require vendor/laravel-log-explorer
```

Visit `/admin/logs` (configurable) — or embed it:

```blade
<x-log-explorer />
```

---

## Why it's fast

Nothing ever loads a whole file. Every operation is byte-offset based and
streamed via `fopen`/`fseek`/`fread`/`fgets`:

| Operation        | Technique                                                        | Cost            |
|------------------|------------------------------------------------------------------|-----------------|
| Tail last N      | Reverse chunk-scan from EOF until N newlines (`LineScanner`)      | O(bytes of last N lines) |
| Pagination       | Opaque **byte-offset cursors** — `fseek` to any page             | O(1) seek       |
| Previous page    | Backward chunk-scan to the boundary N lines up, then read forward | O(page)         |
| Search           | Streamed record-by-record, or shell out to **ripgrep/grep** over a byte range | O(range)        |
| Time window      | `from`/`to` **binary-searched** to byte offsets before scanning (~40 seeks for 100 GB) | O(window)       |
| Search, next page| Cursor = start of the next scan; never re-reads earlier pages    | O(page)         |
| Newest first     | Windows (1 MB, doubling to 64 MB) scanned back from EOF (`direction=backward`) | O(window)       |
| Count / histogram| Matches per minute/hour/day over the seeked range, no lines       | O(window)       |
| Live tail        | Poll size, read only appended bytes (`tail -f`)                   | O(new bytes)    |
| Download         | Chunked `fread` stream, never buffered                           | O(chunk)        |

Long lines are capped (`max_line_length`) while byte offsets stay exact, so a
pathological newline-free file still can't exhaust memory.

The time seek assumes a log is appended in time order (Laravel logs are). Lines
up to `search.time_seek_margin` seconds (default 300) out of order are still
found, and the exact date filter always runs on every record. A file with no
timestamps is searched in full. rg/grep run under `nice`/`ionice`, so a big
search doesn't starve the app.

---

## Installation

```bash
composer require vendor/laravel-log-explorer

php artisan vendor:publish --tag=log-explorer-config   # config/log-explorer.php
php artisan vendor:publish --tag=log-explorer-assets   # public/vendor/log-explorer
php artisan vendor:publish --tag=log-explorer-views    # (optional) customise UI
```

Define the authorization gates in `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewLogs',     fn ($user) => $user->isAdmin());
Gate::define('downloadLogs', fn ($user) => $user->isSuperAdmin());
```

That's it — open `/admin/logs`.

---

## Integration modes

**Standalone route** (default): `config('log-explorer.routes.prefix')` → `/admin/logs`.

**Blade component / embedded widget** — works inside any dashboard:

```blade
{{-- Full viewer --}}
<x-log-explorer />

{{-- Open straight into a 500-line tail of a specific file, constrained height --}}
<x-log-explorer :tail="500" height="600px" />
```

To render the standalone route *inside your app's chrome*, set
`ui.layout` in the config to your layout view name.

---

## Configuration highlights

```php
// config/log-explorer.php
'sources' => ['drivers' => ['local' => ['paths' => [
    storage_path('logs'), '/var/log/nginx', '/var/log/custom-app',
]]]],

'files'   => ['allowed_extensions' => ['log', 'txt'], 'hidden' => ['*.key', '.env*']],

'search'  => [
    'mode' => 'auto',                // ripgrep → grep → PHP stream
    'time_seek' => true,             // binary-search from/to into a byte range
    'time_seek_margin' => 300,       // seconds of clock skew to tolerate
    'backward_window' => 64 << 20,   // bytes per newest-first window
    'forward_piece' => 64 << 20,     // rg/grep pieces start at 1 MB, double to 1 GB; a timeout still advances the cursor
    'nice' => 10, 'ionice' => true,  // run rg/grep at low CPU/IO priority
],
'download'=> ['max_download_size_mb' => 100, 'rate_limit' => '10,1', 'audit' => true],
'streaming' => ['driver' => 'sse', 'max_duration' => 60],
```

See the published config for every option (all documented inline).

---

## JSON API

All endpoints are under `{prefix}/api` and are guarded by the view gate.

| Method & path            | Purpose                                  | Key query params                              |
|--------------------------|------------------------------------------|-----------------------------------------------|
| `GET /api/files`         | List log files                           | `source`                                      |
| `GET /api/files/show`    | File metadata                            | `file`                                        |
| `GET /api/view`          | Cursor-paginated read                    | `file`, `cursor`, `direction`, `limit`        |
| `GET /api/tail`          | Last N lines                             | `file`, `lines`                               |
| `GET /api/search`        | Streamed search                          | `file`, `q`, `regex`, `case`, `level`, `from`, `to`, `cursor`, `direction=backward` (newest first) |
| `GET /api/count`         | Match counts per time bucket, no lines   | `file`, `q`, `regex`, `case`, `level`, `from`, `to`, `cursor`, `bucket=minute\|hour\|day` |
| `GET /api/stream`        | SSE live tail                            | `file`, `cursor`                              |
| `GET /api/download`      | Guarded streamed download                | `file`, `confirm`, `mode=tail&lines=N`        |

Cursors are opaque base64 byte offsets carrying a file fingerprint, so a
rotated/truncated file is detected and reset rather than returning garbage.
A backward cursor is an exclusive upper bound; pass it back with the same
`direction`. Search responses report `meta.range` (the byte range scanned
after the time seek and cursor) and `meta.direction`.

From PHP, the same operations are on `SearchManager`:

```php
$search = app(\Vendor\LogExplorer\Search\SearchManager::class);

$newest = $search->search($file, new SearchCriteria(
    query: 'shop-42', dateFrom: 'today 14:00', limit: 50, direction: 'backward',
));

$perHour = $search->count([$file], new SearchCriteria(query: 'SQLSTATE', dateFrom: '-24 hours'), 'hour');
// $perHour->buckets = ['2026-11-05 13:00' => 4, '2026-11-05 14:00' => 311, ...]
```

---

## Extensibility

```php
use Vendor\LogExplorer\Facades\LogExplorer;

// Custom parser (Apache, syslog, your own format)
LogExplorer::extendParser('apache', ApacheLogParser::class);

// Custom source (future: SSH, S3, remote) — implement LogSourceInterface
LogExplorer::extendSource('s3', fn (array $cfg) => new S3LogSource($cfg));

// Custom authorization
LogExplorer::authorizeUsing(fn ($user, $ability, $file) => $user?->can($ability));
```

`LogSourceInterface` + `LocalLogSource` are the seam for multi-server support —
the entire reading engine is written against the interface and a seekable
stream, so a remote driver is a drop-in.

---

## Testing

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Feature
vendor/bin/phpunit --testsuite Performance               # builds a 128 MB file

# Prove the bounds against a genuinely huge file:
LOG_EXPLORER_PERF_GB=10 vendor/bin/phpunit --testsuite Performance
```

The performance suite asserts every operation allocates **under 50 MB** even on
multi-GB files, and that a last-hour search, a last-hour count and a
newest-first page are bounded by the time/byte window, not the file size.
Search tests run on every engine present on the host (the PHP streamer always,
plus grep and ripgrep when installed), so run the suite on a machine with
`rg` to cover the ripgrep path. Fixtures are generated by streaming writes (see
`tests/Support/FixtureGenerator.php`).

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design, directory map, and
extensibility roadmap.

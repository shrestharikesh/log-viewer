# Architecture & Deliverables — laravel-log-explorer

This document maps each requested deliverable to its implementation.

## 1. Package architecture

Layered, single-responsibility, framework-idiomatic. The HTTP layer is thin;
all logic lives in testable, framework-agnostic services that depend only on
the `LogSourceInterface` and PHP streams.

```
HTTP (controllers)  →  Services (reading/search/streaming/parsing)
                    →  Source abstraction (LogSourceInterface)
                    →  Low-level streaming (LineScanner: fseek/fread/fgets)
```

## 2. Directory structure

```
laravel-log-explorer/
├── composer.json                 # PSR-4, auto-discovery (provider + facade)
├── phpunit.xml                   # Unit / Feature / Performance suites
├── README.md  ARCHITECTURE.md  LICENSE
├── config/
│   └── log-explorer.php          # fully-documented config
├── routes/
│   └── web.php                   # UI + JSON API routes
├── resources/
│   ├── views/
│   │   ├── index.blade.php        # standalone page (or host-layout embed)
│   │   └── components/log-explorer.blade.php   # <x-log-explorer/>
│   └── dist/
│       ├── log-explorer.js        # Alpine controller (published to public/)
│       └── log-explorer.css
├── src/
│   ├── LogExplorerServiceProvider.php
│   ├── LogExplorer.php            # registry: parsers, sources, auth callback
│   ├── Facades/LogExplorer.php
│   ├── Contracts/                 # LogSourceInterface, LogParserInterface
│   ├── Exceptions/
│   ├── Support/                   # LogFile, PathValidator, ByteCursor, DTOs
│   ├── Reading/                   # LineScanner, RecordAssembler, TimeOffsetLocator, FileReader, Tailer
│   ├── Parsing/                   # ParserManager + Laravel/JSON parsers
│   ├── Search/                    # SearchManager, SearchRunner, StreamSearcher, CommandLineSearcher
│   ├── Streaming/                 # LogStreamer (SSE)
│   ├── Auth/                      # Authorizer
│   ├── Sources/                   # LocalLogSource
│   ├── View/Components/           # LogExplorer Blade component
│   └── Http/
│       ├── Controllers/           # one per endpoint + UI
│       └── Middleware/Authorize.php
└── tests/
    ├── TestCase.php  Support/FixtureGenerator.php
    ├── Unit/  Feature/  Performance/
```

## 3. Database requirements

**None.** The package is stateless. Audit logging uses Laravel's logging
(`config('log-explorer.download.audit_channel')`); download rate limiting uses
the cache-backed `RateLimiter`. No migrations, no tables.

## 4. API design

JSON-first; the UI is a pure consumer. See the table in `README.md`. Pagination
and search both use opaque byte-offset cursors (`Support/ByteCursor`) carrying a
`size:mtime` fingerprint for rotation safety. Responses are uniform
(`PageResult` / `SearchResult` `jsonSerialize`).

## 5. Service provider

`LogExplorerServiceProvider`:
- `register()` merges config and binds `LineScanner`, `FileReader`, `Tailer`,
  `SearchManager`, `LogStreamer`, `Authorizer`, and the `LogExplorer` registry
  singleton — each wired to the configured source.
- `boot()` loads views, registers `<x-log-explorer/>`, registers the route
  group (configurable prefix / name / middleware + the package `Authorize`
  middleware), and declares the three publish tags (config, views, assets).

## 6. Config file design

`config/log-explorer.php` — sections: `routes`, `authorization`, `sources`,
`files`, `reading`, `tail`, `search`, `download`, `streaming`, `parsing`, `ui`.
Every key is documented inline.

## 7. Authorization strategy

Defence in depth, **secure by default**:
1. Route middleware (`web`, `auth`, …) — configurable.
2. Package `Authorize` middleware enforces the **view** gate on every route.
3. The download controller separately enforces the **download** gate against the
   specific file.
4. `Authorizer` resolves: runtime callback → config callback → Gate. With
   `authorization.strict = true` (default), an **undefined gate denies access**.
5. `PathValidator` canonicalises every path (`realpath`) and confirms
   containment within a configured root — blocking traversal — plus extension
   allow-listing and hidden-pattern rejection. Files are referenced by opaque
   identifiers, never raw client paths.

## 8. Frontend architecture

TailwindCSS + Alpine.js. A single Alpine component (`resources/dist/log-explorer.js`)
holds a sliding window of lines and talks to the JSON API. Features: dark mode,
search, level/regex/date filters, cursor pagination + infinite scroll, tail
presets, live follow (SSE), file selector, guarded download, copy-line,
expandable entries, JSON pretty-printing. Ships as a Blade component so it
embeds into Filament/Nova/custom dashboards; Alpine is lazy-loaded only if the
host page hasn't already provided it.

## 9. Performance strategy

- **Never** `file_get_contents` / `file()` / `Storage::get()` on whole files.
- `LineScanner` does all I/O in fixed chunks with hard per-line caps.
- Reverse scanning for tail/previous; `fseek` for forward jumps.
- Search streams and stops at a result limit or wall-clock budget, returning a
  resume cursor; ripgrep/grep used when a local path is available.
- **Every search scans a byte range, never "the file".** `SearchRunner` works
  out a range `[start, end)` for each file and the engine
  (`RangeScanner::scanRange`) only reads that:
  - **Time-window seek.** `from`/`to` are binary-searched into byte offsets by
    `Reading\TimeOffsetLocator`: probe a byte offset, read forward to the next
    timestamped record header, compare, narrow. That is about 40 probes for
    100 GB. The range is widened by `search.time_seek_margin` (default 300 s)
    for clock skew between workers, and the exact date filter still runs on
    every record. When a probe can't decide, the locator errs wide: the start
    moves earlier, the end later. A file with no timestamped header in its
    first MB (plain text, JSON without a time field) gets the full range.
    Range edges are always record headers, so a record is never split. The
    one assumption is that files are appended in time order, give or take the
    margin; set `search.time_seek` to false for files that are not.
  - **Cursor resume is O(page).** The cursor is the range start, so page N
    reads from the cursor, not from byte 0.
  - **Ranged CLI scans.** rg/grep have no offset flag, so a ranged scan feeds
    exactly `[start, end)` to the process on stdin (1 MB `fread` chunks through
    Symfony Process; one chunk in memory at a time) and adds `start` back to
    each `--byte-offset`/`-b`. A whole-file scan still passes the path. A plain
    whole-file multi-file search stays one process; a ranged one runs one
    process per file, in turn, under the shared limit and timeout.
  - **Newest first** (`direction=backward`). Windows are scanned back from
    the end of the range, each aligned to a record header: 1 MB first, then
    doubling up to `search.backward_window` (default 64 MB). A window that
    does not finish in time is discarded, so the small first window is what
    guarantees a slow engine still moves the cursor every page. A ring
    buffer keeps the newest `limit` matches per window, and the cursor is an
    exclusive upper bound. A page costs at most about one full window,
    whatever the file size.
  - **Counts** (`SearchManager::count`, `GET /api/count`). Matches per
    minute/hour/day bucket over the seeked range, without returning lines.
    One multi-line record counts once, the same unit search returns (a hit on
    a stack-trace line is attributed to its record's header). In count mode
    the CLI engines parse header-line hits straight from the rg/grep output,
    with no file read; any other hit is folded exactly as search folds it, so
    header-less lines and lines past the continuation cap count the same in
    both. Count cursors are forward only: `/api/count` rejects a newest-first
    cursor with 422. Past 10,000 buckets, buckets merge into the next
    coarser granularity to keep memory bounded.
- **Known limits of the seek and ranged scans** (all err toward returning
  less only where noted, never wrong records):
  - Records with no timestamp that sit *outside* the seeked range (for
    example an orphan stack-trace tail at the top of a rotated file, or
    timestamp-less JSON interleaved with dated lines) are not returned by a
    date-filtered search. An unseeked search would return them, because the
    post-filter lets undated records through.
  - Out-of-order lines beyond the margin can be missed near the boundary.
    That includes a DST fall-back hour when the app timezone observes DST: the
    repeated hour steps back 3600 s, more than the default margin. Run the app
    in UTC, or raise `time_seek_margin`.
  - A forward rg/grep scan that times out before its first match returns the
    same cursor it was given. The engine can't report how far it read. The
    seek keeps ranges small enough that this needs a rare term over a very
    wide range. The PHP streamer does report progress.
- An rg/grep error (exit code ≥ 2, e.g. a regex the engine's dialect
  rejects) raises `SearchFailedException` (HTTP 422) instead of looking like
  "no matches". Exit 2 caused only by one input (a file rotated away or
  unreadable in a multi-file search) is not fatal: the other files' results
  stand. Searched paths in the error text are cut to their basename before
  it reaches the client. Hits past the file's size snapshot (lines appended
  while rg reads the live file) are dropped. Queries go to rg with `-e`, so
  a query starting with `-` is always the pattern, never an option.
- rg/grep run under `nice -n 10` and, on Linux, `ionice -c3 -t`
  (`search.nice`, `search.ionice`), so a big search yields CPU and disk to
  the app it is diagnosing. Both are argv prefixes; there is still no shell.
- SSE reads only newly appended bytes per tick, byte-budgeted.
- Downloads stream in chunks; oversized downloads are blocked with a tail
  escape hatch.
- Verified by `tests/Performance` asserting <50 MB allocation on 128 MB → 50 GB+
  files, and that a last-hour search, a last-hour count and a newest-first
  page are bounded by the byte range they cover, not by the file size.
  (Measured on 1 GB: last-hour rg search 1.96 s → 0.03 s; the pure-PHP
  filter-only path went from a 120 s timeout to 0.31 s.)

## 10. Example installation

See `README.md` → Installation.

## 11. Future extensibility roadmap

| Area              | Seam today                                  | Future                                   |
|-------------------|---------------------------------------------|------------------------------------------|
| Multi-server      | `LogSourceInterface` + `extendSource()`     | `SshLogSource`, `S3LogSource`, `HttpLogSource` (proxy seeks / ranged GET) |
| Parsers           | `LogParserInterface` + `extendParser()`     | Apache, Nginx, syslog, GELF bundled      |
| Live updates      | `streaming.driver` (`sse` today)            | `websockets` (Reverb/Pusher) driver      |
| Search backends   | `Searcher` + `RangeScanner` + `SearchManager` | OpenSearch/Loki adapter for archives     |
| Big-file search   | Time seek, ranged scans, newest-first, counts (1.2) | Sparse on-disk time index for files that aren't time-ordered; level pre-filter via rg for filter-only searches |
| Auth              | `authorizeUsing()` callback                 | Per-file / per-directory policies        |
| UI                | Published Blade + Alpine                    | Optional Vue build, saved filters, alerts|

### Extending — example custom source

```php
class S3LogSource implements LogSourceInterface {
    // files(), resolve(), size(), reader() returning a seekable stream
    // (e.g. a stream wrapper doing ranged GETs), localPath() => null
}
LogExplorer::extendSource('s3', fn ($cfg) => new S3LogSource($cfg));
```

Because the entire engine consumes a seekable stream behind the interface,
adding a provider requires no changes to reading, search, tail, or streaming.

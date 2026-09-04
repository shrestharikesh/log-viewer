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
│   ├── Reading/                   # LineScanner, FileReader, Tailer
│   ├── Parsing/                   # ParserManager + Laravel/JSON parsers
│   ├── Search/                    # SearchManager, StreamSearcher, CommandLineSearcher
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
- SSE reads only newly appended bytes per tick, byte-budgeted.
- Downloads stream in chunks; oversized downloads are blocked with a tail
  escape hatch.
- Verified by `tests/Performance` asserting <50 MB allocation on 128 MB → 50 GB+
  files.

## 10. Example installation

See `README.md` → Installation.

## 11. Future extensibility roadmap

| Area              | Seam today                                  | Future                                   |
|-------------------|---------------------------------------------|------------------------------------------|
| Multi-server      | `LogSourceInterface` + `extendSource()`     | `SshLogSource`, `S3LogSource`, `HttpLogSource` (proxy seeks / ranged GET) |
| Parsers           | `LogParserInterface` + `extendParser()`     | Apache, Nginx, syslog, GELF bundled      |
| Live updates      | `streaming.driver` (`sse` today)            | `websockets` (Reverb/Pusher) driver      |
| Search backends   | `Searcher` interface + `SearchManager`      | OpenSearch/Loki adapter for archives     |
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

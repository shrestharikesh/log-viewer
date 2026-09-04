@php
    /** @var \Vendor\LogExplorer\View\Components\LogExplorer $component */
    $jsConfig = [
        'endpoints' => $endpoints(),
        'csrf' => csrf_token(),
        'initialFile' => $file,
        'initialTail' => $tail,
        'source' => $source,
        'tailPresets' => (array) config('log-explorer.tail.presets', [100, 500, 1000, 5000]),
        'pageSize' => (int) config('log-explorer.reading.page_size', 200),
        'streamingEnabled' => (bool) config('log-explorer.streaming.enabled', true),
        'levels' => ['emergency','alert','critical','error','warning','notice','info','debug'],
    ];
@endphp

{{-- Load Alpine only if the host page hasn't already. --}}
@once
    <script>
        if (!window.__logExplorerAlpine && typeof window.Alpine === 'undefined') {
            window.__logExplorerAlpine = true;
            var s = document.createElement('script');
            s.defer = true;
            s.src = 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js';
            document.head.appendChild(s);
        }
    </script>
@endonce
<script src="{{ asset('vendor/log-explorer/log-explorer.js') }}" defer></script>

<div
    x-data="logExplorer(@js($jsConfig))"
    x-init="init()"
    class="le-root flex flex-col rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden"
    style="height: {{ $height }}"
>
    {{-- ============================ Toolbar ============================ --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-gray-800 px-3 py-2 bg-gray-50 dark:bg-gray-900/60">
        {{-- File selector --}}
        <select x-model="currentFileId" @change="onFileChange()"
                class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 max-w-xs">
            <option value="">Select a log file…</option>
            <template x-for="f in files" :key="f.identifier">
                <option :value="f.identifier" x-text="`${f.relative_path} (${f.size_human})`"></option>
            </template>
        </select>

        {{-- Search --}}
        <div class="flex items-center gap-1 flex-1 min-w-[200px]">
            <input type="search" x-model="search.q" @keydown.enter="runSearch()"
                   placeholder="Search…" :disabled="!currentFileId"
                   class="text-sm w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <button @click="runSearch()" :disabled="!currentFileId"
                    class="le-btn" title="Search">🔍</button>
        </div>

        {{-- Mode buttons --}}
        <div class="flex items-center rounded-md border border-gray-300 dark:border-gray-700 overflow-hidden text-sm">
            <button @click="loadHead()" :class="modeClass('view')" class="le-mode">Head</button>
            <button @click="loadTail()" :class="modeClass('tail')" class="le-mode">Tail</button>
            <button x-show="config.streamingEnabled" @click="toggleFollow()"
                    :class="following ? 'bg-emerald-600 text-white' : ''" class="le-mode" x-text="following ? '● Live' : 'Follow'"></button>
        </div>

        {{-- Tail size --}}
        <select x-model.number="tailLines" @change="mode==='tail' && loadTail()"
                class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <template x-for="n in config.tailPresets" :key="n">
                <option :value="n" x-text="`Last ${n}`"></option>
            </template>
        </select>

        <div class="flex items-center gap-2 ml-auto">
            <button @click="download()" :disabled="!currentFileId" class="le-btn" title="Download">⬇</button>
            <button @click="dark = !dark; $root.closest('html')?.classList.toggle('dark')" class="le-btn" title="Toggle theme">🌓</button>
        </div>
    </div>

    {{-- ============================ Filters =========================== --}}
    <div class="flex flex-wrap items-center gap-3 px-3 py-1.5 border-b border-gray-200 dark:border-gray-800 text-xs">
        <label class="flex items-center gap-1">
            <span>Level</span>
            <select x-model="search.level" @change="runSearch()" class="text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 py-0.5">
                <option value="">all</option>
                <template x-for="lvl in config.levels" :key="lvl">
                    <option :value="lvl" x-text="lvl"></option>
                </template>
            </select>
        </label>
        <label class="flex items-center gap-1"><input type="checkbox" x-model="search.regex"> regex</label>
        <label class="flex items-center gap-1"><input type="checkbox" x-model="search.case"> case</label>
        <label class="flex items-center gap-1">from <input type="datetime-local" x-model="search.from" class="text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 py-0.5"></label>
        <label class="flex items-center gap-1">to <input type="datetime-local" x-model="search.to" class="text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 py-0.5"></label>
        <label class="flex items-center gap-1 ml-auto"><input type="checkbox" x-model="autoscroll"> auto-scroll</label>
        <label class="flex items-center gap-1"><input type="checkbox" x-model="wrap"> wrap</label>
        <span x-show="engine" class="text-gray-400" x-text="`engine: ${engine}`"></span>
    </div>

    {{-- ============================ Log area ========================== --}}
    <div x-ref="scroller" @scroll.debounce.150ms="onScroll()"
         class="flex-1 overflow-auto font-mono text-xs leading-relaxed bg-white dark:bg-gray-950">

        <div x-show="!currentFileId" class="p-8 text-center text-gray-400">Select a log file to begin.</div>
        <div x-show="loading" class="p-3 text-center text-gray-400">Loading…</div>

        <div x-show="canPrev && currentFileId" class="p-2 text-center">
            <button @click="loadPrev()" class="le-btn">↑ Load previous</button>
        </div>

        <template x-for="(line, i) in lines" :key="line.offset + '-' + i">
            <div class="le-line group border-b border-gray-100 dark:border-gray-900 px-3 py-1 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                 :class="wrap ? 'whitespace-pre-wrap break-all' : 'whitespace-pre overflow-x-auto'">
                <div class="flex items-start gap-2">
                    <button @click="line._open = !line._open"
                            x-show="hasDetail(line)"
                            class="text-gray-400 shrink-0 w-3" x-text="line._open ? '▾' : '▸'"></button>
                    <span x-show="!hasDetail(line)" class="w-3 shrink-0"></span>

                    <span x-show="line.parsed && line.parsed.level"
                          class="shrink-0 rounded px-1.5 text-[10px] font-semibold uppercase"
                          :class="levelClass(line.parsed.level)" x-text="line.parsed.level"></span>

                    <span x-show="line.parsed && line.parsed.datetime"
                          class="shrink-0 text-gray-400" x-text="line.parsed.datetime"></span>

                    <span class="flex-1" x-text="displayMessage(line)"></span>

                    <button @click="copyLine(line)" class="opacity-0 group-hover:opacity-100 text-gray-400 shrink-0" title="Copy">⧉</button>
                </div>

                {{-- Expanded detail: context JSON + stack trace --}}
                <div x-show="line._open" x-cloak class="mt-1 ml-5 space-y-1">
                    <template x-if="line.parsed && line.parsed.context && Object.keys(line.parsed.context).length">
                        <pre class="rounded bg-gray-100 dark:bg-gray-900 p-2 overflow-x-auto" x-text="pretty(line.parsed.context)"></pre>
                    </template>
                    <template x-for="ex in (line.parsed ? line.parsed.extra : [])" :key="ex">
                        <div class="text-gray-500 dark:text-gray-400 pl-2 border-l-2 border-gray-200 dark:border-gray-800" x-text="ex"></div>
                    </template>
                </div>
            </div>
        </template>

        {{-- infinite-scroll sentinel --}}
        <div x-ref="sentinel" class="h-4"></div>
        <div x-show="!loading && currentFileId && lines.length === 0" class="p-8 text-center text-gray-400">No log entries.</div>
    </div>

    {{-- ============================ Footer ============================ --}}
    <div class="flex items-center gap-2 border-t border-gray-200 dark:border-gray-800 px-3 py-1.5 text-xs bg-gray-50 dark:bg-gray-900/60">
        <button @click="loadPrev()" :disabled="!canPrev" class="le-btn">‹ Prev</button>
        <button @click="loadNext()" :disabled="!canNext" class="le-btn">Next ›</button>
        <button @click="loadTail()" :disabled="!currentFileId" class="le-btn">⤓ Latest</button>
        <span class="ml-auto text-gray-400" x-text="`${lines.length} lines${searchActive ? ' (search)' : ''}`"></span>
        <span x-show="error" class="text-red-500" x-text="error"></span>
    </div>
</div>

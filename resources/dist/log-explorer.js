/**
 * Log Explorer — Alpine.js front-end controller.
 *
 * Talks exclusively to the JSON API. Holds at most a sliding window of lines in
 * the DOM; all heavy lifting (tail, paging, search, streaming) is server-side
 * and byte-offset based, so the UI stays light regardless of file size.
 */
(function () {
    function logExplorer(config) {
        return {
            config: config,
            files: [],
            currentFileId: config.initialFile || '',
            lines: [],
            cursor: { next: null, previous: null },
            mode: 'tail', // 'view' | 'tail' | 'search'
            tailLines: config.initialTail || (config.tailPresets[0] || 100),
            search: { q: '', regex: false, case: false, level: '', from: '', to: '' },
            searchActive: false,
            engine: '',
            following: false,
            autoscroll: true,
            wrap: false,
            loading: false,
            error: '',
            dark: false,
            _es: null,        // EventSource
            _maxLines: 5000,  // DOM cap; older lines are trimmed when following

            get canNext() { return !!this.cursor.next; },
            get canPrev() { return !!this.cursor.previous; },

            async init() {
                await this.loadFiles();
                if (this.currentFileId) await this.loadTail();
            },

            // ---- API plumbing -------------------------------------------------
            url(name, params) {
                const u = new URL(this.config.endpoints[name], window.location.origin);
                if (this.currentFileId) u.searchParams.set('file', this.currentFileId);
                if (this.config.source) u.searchParams.set('source', this.config.source);
                Object.entries(params || {}).forEach(([k, v]) => {
                    if (v !== null && v !== undefined && v !== '') u.searchParams.set(k, v);
                });
                return u.toString();
            },

            async get(name, params) {
                this.error = '';
                this.loading = true;
                try {
                    const res = await fetch(this.url(name, params), {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
                    });
                    if (!res.ok) {
                        const body = await res.json().catch(() => ({}));
                        throw new Error(body.message || `Request failed (${res.status})`);
                    }
                    return await res.json();
                } catch (e) {
                    this.error = e.message;
                    return null;
                } finally {
                    this.loading = false;
                }
            },

            // ---- Files --------------------------------------------------------
            async loadFiles() {
                const data = await this.get('files');
                if (data) this.files = data.data || [];
            },

            onFileChange() {
                this.stopFollow();
                this.searchActive = false;
                this.search.q = '';
                this.loadTail();
            },

            // ---- Reading modes ------------------------------------------------
            async loadHead() {
                this.stopFollow();
                this.mode = 'view';
                this.searchActive = false;
                const data = await this.get('view', { direction: 'forward', limit: this.config.pageSize });
                if (data) this.replaceLines(data, true);
            },

            async loadTail() {
                this.stopFollow();
                this.mode = 'tail';
                this.searchActive = false;
                const data = await this.get('tail', { lines: this.tailLines });
                if (data) { this.replaceLines(data, false); this.scrollToBottom(); }
            },

            async loadNext() {
                if (!this.cursor.next) return;
                const ep = this.searchActive ? 'search' : 'view';
                const params = this.searchActive
                    ? this.searchParams({ cursor: this.cursor.next })
                    : { direction: 'forward', limit: this.config.pageSize, cursor: this.cursor.next };
                const data = await this.get(ep, params);
                if (data) this.appendLines(data, 'bottom');
            },

            async loadPrev() {
                if (!this.cursor.previous || this.searchActive) return;
                const data = await this.get('view', {
                    direction: 'previous', limit: this.config.pageSize, cursor: this.cursor.previous,
                });
                if (data) this.appendLines(data, 'top');
            },

            // ---- Search -------------------------------------------------------
            searchParams(extra) {
                return Object.assign({
                    q: this.search.q,
                    regex: this.search.regex ? 1 : 0,
                    case: this.search.case ? 1 : 0,
                    level: this.search.level,
                    from: this.search.from,
                    to: this.search.to,
                    limit: this.config.pageSize,
                }, extra || {});
            },

            async runSearch() {
                if (!this.currentFileId) return;
                this.stopFollow();
                // Empty query with a level filter is still a valid filter search.
                if (!this.search.q && !this.search.level && !this.search.from && !this.search.to) {
                    return this.loadTail();
                }
                this.mode = 'search';
                this.searchActive = true;
                const data = await this.get('search', this.searchParams());
                if (data) {
                    this.lines = (data.matches || []).map(this.decorate);
                    this.cursor = { next: data.cursor ? data.cursor.next : null, previous: null };
                    this.engine = data.meta ? data.meta.engine : '';
                    this.scrollToTop();
                }
            },

            // ---- Live follow (SSE) -------------------------------------------
            toggleFollow() { this.following ? this.stopFollow() : this.startFollow(); },

            startFollow() {
                if (!this.currentFileId || !this.config.streamingEnabled) return;
                this.following = true;
                this.mode = 'tail';
                const url = this.url('stream', { cursor: this.cursor.next || '' });
                this._es = new EventSource(url);
                this._es.addEventListener('append', (e) => {
                    const payload = JSON.parse(e.data);
                    (payload.lines || []).forEach((l) => this.lines.push(this.decorate(l)));
                    this.trimWindow();
                    if (this.autoscroll) this.scrollToBottom();
                });
                this._es.addEventListener('rotated', () => { this.lines = []; });
                this._es.addEventListener('reconnect', (e) => {
                    // Browser will auto-reconnect; nothing required here.
                });
                this._es.onerror = () => { /* EventSource auto-retries */ };
            },

            stopFollow() {
                this.following = false;
                if (this._es) { this._es.close(); this._es = null; }
            },

            // ---- Download -----------------------------------------------------
            download() {
                if (!this.currentFileId) return;
                const file = this.files.find((f) => f.identifier === this.currentFileId);
                window.open(this.url('download', { confirm: 1 }), '_blank');
            },

            // ---- Rendering helpers -------------------------------------------
            decorate(line) { line._open = false; return line; },

            replaceLines(data, scrollTop) {
                this.lines = (data.lines || []).map(this.decorate);
                this.cursor = data.cursor || { next: null, previous: null };
                this.engine = '';
                if (scrollTop) this.scrollToTop();
            },

            appendLines(data, where) {
                const incoming = (data.lines || data.matches || []).map(this.decorate);
                if (where === 'top') {
                    const scroller = this.$refs.scroller;
                    const prevH = scroller.scrollHeight;
                    this.lines = incoming.concat(this.lines);
                    this.cursor.previous = data.cursor ? data.cursor.previous : null;
                    this.$nextTick(() => { scroller.scrollTop = scroller.scrollHeight - prevH; });
                } else {
                    this.lines = this.lines.concat(incoming);
                    this.cursor.next = data.cursor ? data.cursor.next : null;
                }
            },

            trimWindow() {
                if (this.lines.length > this._maxLines) {
                    this.lines.splice(0, this.lines.length - this._maxLines);
                }
            },

            onScroll() {
                if (this.loading || this.following) return;
                const s = this.$refs.scroller;
                if (s.scrollTop + s.clientHeight >= s.scrollHeight - 40 && this.canNext) this.loadNext();
                else if (s.scrollTop <= 40 && this.canPrev && !this.searchActive) this.loadPrev();
            },

            scrollToBottom() { this.$nextTick(() => { const s = this.$refs.scroller; s.scrollTop = s.scrollHeight; }); },
            scrollToTop() { this.$nextTick(() => { this.$refs.scroller.scrollTop = 0; }); },

            displayMessage(line) {
                if (line.parsed && line.parsed.message !== null && line.parsed.message !== undefined) return line.parsed.message;
                return line.raw + (line.truncated ? ' …(truncated)' : '');
            },

            hasDetail(line) {
                return !!(line.parsed && ((line.parsed.context && Object.keys(line.parsed.context).length) ||
                    (line.parsed.extra && line.parsed.extra.length)));
            },

            pretty(obj) { try { return JSON.stringify(obj, null, 2); } catch (e) { return String(obj); } },

            copyLine(line) { navigator.clipboard && navigator.clipboard.writeText(line.raw); },

            modeClass(m) { return this.mode === m && !this.following ? 'bg-indigo-600 text-white' : ''; },

            levelClass(level) {
                const map = {
                    emergency: 'bg-red-700 text-white', alert: 'bg-red-700 text-white',
                    critical: 'bg-red-600 text-white', error: 'bg-red-500 text-white',
                    warning: 'bg-amber-500 text-white', notice: 'bg-sky-500 text-white',
                    info: 'bg-sky-600 text-white', debug: 'bg-gray-500 text-white',
                };
                return map[(level || '').toLowerCase()] || 'bg-gray-400 text-white';
            },
        };
    }

    window.logExplorer = logExplorer;
    document.addEventListener('alpine:init', () => {
        if (window.Alpine) window.Alpine.data('logExplorer', logExplorer);
    });
})();

{{-- Deprecated entrypoint kept for backwards compatibility. --}}
@include(config('log-explorer.ui.layout') ? 'log-explorer::embedded' : 'log-explorer::standalone')

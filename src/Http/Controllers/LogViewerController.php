<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET {prefix} — the standalone UI page. Thin: it just renders the Blade view,
 * which boots the Alpine app that talks to the JSON API. The view honours
 * config('log-explorer.ui.layout') so it can be embedded in an app's chrome.
 */
final class LogViewerController
{
    public function __invoke(Request $request): View
    {
        $view = config('log-explorer.ui.layout')
            ? 'log-explorer::embedded'
            : 'log-explorer::standalone';

        return view($view, [
            'config' => [
                'apiBase' => route('log-explorer.api.files'),
                'endpoints' => [
                    'files' => route('log-explorer.api.files'),
                    'show' => route('log-explorer.api.files.show'),
                    'view' => route('log-explorer.api.view'),
                    'tail' => route('log-explorer.api.tail'),
                    'search' => route('log-explorer.api.search'),
                    'stream' => route('log-explorer.api.stream'),
                    'download' => route('log-explorer.api.download'),
                ],
                'tailPresets' => (array) config('log-explorer.tail.presets', [100, 500, 1000, 5000]),
                'pageSize' => (int) config('log-explorer.reading.page_size', 200),
                'theme' => config('log-explorer.ui.theme', 'system'),
                'title' => config('log-explorer.ui.title', 'Log Explorer'),
                'streamingEnabled' => (bool) config('log-explorer.streaming.enabled', true),
            ],
        ]);
    }
}

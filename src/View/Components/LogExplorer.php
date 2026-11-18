<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\View\Components;

use Illuminate\View\Component;

/**
 * Embeddable widget: <x-log-explorer /> drops the full viewer into any Blade
 * page (Filament, Nova custom page, Tailwind/Bootstrap dashboard, ...).
 *
 *   <x-log-explorer />                       full viewer
 *   <x-log-explorer file="..." :tail="500"/> open straight into a tail
 *   <x-log-explorer height="600px" />        constrain height when embedded
 */
class LogExplorer extends Component
{
    /**
     * Used when `ui.alpine` is absent (e.g. a config published before the key
     * existed). Keep in sync with config/log-explorer.php. The hash is the
     * SHA-384 of that exact file; recompute it whenever the version changes:
     *   curl -s URL | openssl dgst -sha384 -binary | openssl base64 -A
     */
    public const ALPINE = [
        'url' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.17.5/dist/cdn.min.js',
        'integrity' => 'sha384-FYLKjwG3aohNhNZoVG8sY/5ntunm+MqylL90Q+rT3OM7AwV9/BKFjk/RjoIpIgsI',
    ];

    public function __construct(
        public ?string $file = null,
        public ?int $tail = null,
        public string $height = '70vh',
        public ?string $source = null,
    ) {
    }

    public function endpoints(): array
    {
        return [
            'files' => route('log-explorer.api.files'),
            'show' => route('log-explorer.api.files.show'),
            'view' => route('log-explorer.api.view'),
            'tail' => route('log-explorer.api.tail'),
            'search' => route('log-explorer.api.search'),
            'stream' => route('log-explorer.api.stream'),
            'download' => route('log-explorer.api.download'),
        ];
    }

    /**
     * The Alpine script to load when the page has none: ['url', 'integrity'],
     * or null when `ui.alpine` is set to null (the host loads Alpine itself).
     *
     * @return array{url:string, integrity:?string}|null
     */
    public function alpine(): ?array
    {
        $alpine = config('log-explorer.ui.alpine', self::ALPINE);

        if (! is_array($alpine) || ! is_string($alpine['url'] ?? null) || $alpine['url'] === '') {
            return null;
        }

        $integrity = $alpine['integrity'] ?? null;

        return [
            'url' => $alpine['url'],
            'integrity' => is_string($integrity) && $integrity !== '' ? $integrity : null,
        ];
    }

    public function render()
    {
        return view('log-explorer::components.log-explorer');
    }
}

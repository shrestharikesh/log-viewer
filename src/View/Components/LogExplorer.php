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

    public function render()
    {
        return view('log-explorer::components.log-explorer');
    }
}

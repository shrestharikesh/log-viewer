<?php

declare(strict_types=1);

namespace Vendor\LogExplorer;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vendor\LogExplorer\Auth\Authorizer;
use Vendor\LogExplorer\Http\Middleware\Authorize;
use Vendor\LogExplorer\Reading\FileReader;
use Vendor\LogExplorer\Reading\LineScanner;
use Vendor\LogExplorer\Reading\Tailer;
use Vendor\LogExplorer\Search\SearchManager;
use Vendor\LogExplorer\Streaming\LogStreamer;
use Vendor\LogExplorer\View\Components\LogExplorer as LogExplorerComponent;

final class LogExplorerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-explorer.php', 'log-explorer');

        // Central registry / extension surface (parsers, sources, auth callback).
        $this->app->singleton(LogExplorer::class, fn (Application $app) => new LogExplorer($app));

        // The active source resolved from config (rebindable per request if a
        // controller passes ?source=). Bound contextually for the readers below.
        $this->app->bind(LineScanner::class, fn (Application $app) => new LineScanner(
            (int) $app['config']->get('log-explorer.reading.chunk_size', 8192),
            (int) $app['config']->get('log-explorer.reading.max_line_length', 32768),
        ));

        $this->app->bind(FileReader::class, fn (Application $app) => new FileReader(
            $app->make(LogExplorer::class)->source(),
            $app->make(LineScanner::class),
            $app->make(LogExplorer::class)->parserManager(),
        ));

        $this->app->bind(Tailer::class, fn (Application $app) => new Tailer(
            $app->make(LogExplorer::class)->source(),
            $app->make(LineScanner::class),
            $app->make(LogExplorer::class)->parserManager(),
        ));

        $this->app->bind(SearchManager::class, fn (Application $app) => new SearchManager(
            $app->make(LogExplorer::class)->source(),
            $app->make(LineScanner::class),
            $app->make(LogExplorer::class)->parserManager(),
            $app->make(Config::class),
        ));

        $this->app->bind(LogStreamer::class, fn (Application $app) => new LogStreamer(
            $app->make(LogExplorer::class)->source(),
            $app->make(LineScanner::class),
            $app->make(LogExplorer::class)->parserManager(),
        ));

        $this->app->singleton(Authorizer::class, fn (Application $app) => new Authorizer(
            $app->make(Gate::class),
            $app->make(Config::class),
            $app->make(LogExplorer::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'log-explorer');
        $this->loadComponentsAndRoutes();
        $this->registerPublishing();
    }

    private function loadComponentsAndRoutes(): void
    {
        // Expose the embeddable widget as <x-log-explorer />.
        \Illuminate\Support\Facades\Blade::component('log-explorer', LogExplorerComponent::class);

        if (! $this->app['config']->get('log-explorer.routes.enabled', true)) {
            return;
        }

        $config = $this->app['config'];

        // Register the package middleware so it can be referenced by alias.
        $this->app['router']->aliasMiddleware('log-explorer.authorize', Authorize::class);

        $middleware = array_merge(
            (array) $config->get('log-explorer.routes.middleware', ['web']),
            ['log-explorer.authorize'],
        );

        $this->app['router']->group([
            'prefix' => $config->get('log-explorer.routes.prefix', 'admin/logs'),
            'as' => $config->get('log-explorer.routes.as', 'log-explorer.'),
            'middleware' => $middleware,
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/log-explorer.php' => config_path('log-explorer.php'),
        ], 'log-explorer-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/log-explorer'),
        ], 'log-explorer-views');

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/log-explorer'),
        ], 'log-explorer-assets');
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Vendor\LogExplorer\Facades\LogExplorer;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

final class HardeningTest extends TestCase
{
    private function id(string $name): string
    {
        return LogFile::makeIdentifier('local', $this->fixture($name));
    }

    /* ------------------------------------------------------------------ */
    /* Per-file view authorization                                         */
    /* ------------------------------------------------------------------ */

    private function twoFiles(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 20);
        FixtureGenerator::laravelLines($this->fixture('payments.log'), 20);
    }

    public function test_view_gate_receives_the_file_and_can_refuse_one(): void
    {
        $this->twoFiles();
        Gate::define('viewLogs', fn ($user = null, ?LogFile $file = null) => $file?->name !== 'payments.log');

        $names = array_column($this->getJson(route('log-explorer.api.files'))->assertOk()->json('data'), 'name');
        $this->assertSame(['app.log'], $names);

        $this->getJson(route('log-explorer.api.tail', ['file' => $this->id('app.log')]))->assertOk();

        foreach (['tail', 'view', 'search', 'count', 'files.show'] as $route) {
            $this->getJson(route("log-explorer.api.{$route}", ['file' => $this->id('payments.log'), 'q' => 'Message']))
                ->assertForbidden();
        }
        $this->get(route('log-explorer.api.download', ['file' => $this->id('payments.log')]))->assertForbidden();
    }

    public function test_authorize_using_callback_receives_the_file(): void
    {
        $this->twoFiles();
        $seen = [];
        LogExplorer::authorizeUsing(function ($user, string $ability, ?LogFile $file) use (&$seen) {
            $seen[] = $file?->name;

            return $file?->name !== 'payments.log';
        });

        $this->getJson(route('log-explorer.api.tail', ['file' => $this->id('payments.log')]))->assertForbidden();

        // Route-level check (no file), then the per-file check.
        $this->assertSame([null, 'payments.log'], $seen);
    }

    /* ------------------------------------------------------------------ */
    /* ?source= and malformed parameters                                   */
    /* ------------------------------------------------------------------ */

    public function test_unknown_or_malformed_source_is_a_404_not_a_500(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        foreach (['nope', 'local.paths', '../local', str_repeat('a', 65)] as $source) {
            $this->getJson(route('log-explorer.api.files', ['source' => $source]))->assertNotFound();
            $this->getJson(route('log-explorer.api.tail', ['file' => $this->id('app.log'), 'source' => $source]))
                ->assertNotFound();
        }

        $this->getJson(route('log-explorer.api.files').'?source[]=local')->assertNotFound();
        $this->getJson(route('log-explorer.api.files', ['source' => 'local']))->assertOk();
    }

    public function test_array_parameters_do_not_cause_server_errors(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 20);
        $file = 'file='.$this->id('app.log');
        $base = fn (string $route) => route("log-explorer.api.{$route}");

        $this->getJson($base('tail').'?file[]=x')->assertStatus(422);
        $this->getJson($base('view')."?{$file}&cursor[]=1")->assertOk();
        $this->getJson($base('search')."?{$file}&q[]=x")->assertOk();
        $this->getJson($base('search')."?{$file}&q=Message&level[]=x&from[]=x&direction[]=x&cursor[]=x")->assertOk();
        $this->getJson($base('count')."?{$file}&q=Message&bucket[]=day")->assertOk();
        $this->getJson($base('tail')."?{$file}&lines[]=5")->assertOk();
    }

    public function test_a_nul_byte_in_the_query_is_rejected(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        $this->getJson(route('log-explorer.api.search', ['file' => $this->id('app.log'), 'q' => "abc\0def"]))
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ */
    /* Rate limits                                                          */
    /* ------------------------------------------------------------------ */

    public function test_search_and_count_share_a_per_minute_limit(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 20);
        config()->set('log-explorer.routes.rate_limits.search', 2);
        $params = ['file' => $this->id('app.log'), 'q' => 'Message'];

        $this->getJson(route('log-explorer.api.search', $params))->assertOk();
        $this->getJson(route('log-explorer.api.count', $params))->assertOk();
        $this->getJson(route('log-explorer.api.search', $params))->assertStatus(429);

        // Reading is not throttled by the search limit.
        $this->getJson(route('log-explorer.api.tail', ['file' => $this->id('app.log')]))->assertOk();
    }

    public function test_a_zero_limit_disables_throttling(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);
        config()->set('log-explorer.routes.rate_limits.search', 0);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson(route('log-explorer.api.search', ['file' => $this->id('app.log'), 'q' => 'x']))->assertOk();
        }
    }

    public function test_expensive_routes_carry_the_throttle(): void
    {
        $routes = app('router')->getRoutes();
        $expected = [
            'search' => 'log-explorer.search',
            'count' => 'log-explorer.search',
            'stream' => 'log-explorer.stream',
        ];

        foreach ($expected as $route => $limiter) {
            $this->assertContains(
                ThrottleRequests::class.':'.$limiter,
                $routes->getByName("log-explorer.api.{$route}")->gatherMiddleware(),
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Front-end assets                                                     */
    /* ------------------------------------------------------------------ */

    public function test_alpine_is_pinned_and_integrity_checked(): void
    {
        $html = Blade::render('<x-log-explorer />');

        $this->assertStringContainsString('alpinejs@3.17.5', $html);
        $this->assertStringContainsString('sha384-', $html);
        $this->assertStringContainsString("crossOrigin = 'anonymous'", $html);
        $this->assertStringNotContainsString('3.x.x', $html);
    }

    public function test_alpine_defaults_apply_to_a_config_published_without_the_key(): void
    {
        config()->set('log-explorer.ui', ['title' => 'Logs']);

        $this->assertStringContainsString('sha384-', Blade::render('<x-log-explorer />'));
    }

    public function test_alpine_loading_can_be_turned_off(): void
    {
        config()->set('log-explorer.ui.alpine', null);

        $this->assertStringNotContainsString('alpinejs', Blade::render('<x-log-explorer />'));
    }

    public function test_standalone_page_loads_no_runtime_css_cdn(): void
    {
        $this->get(route('log-explorer.index'))
            ->assertOk()
            ->assertDontSee('cdn.tailwindcss.com')
            ->assertSee('log-explorer-standalone.css');

        $this->assertFileExists(__DIR__.'/../../resources/dist/log-explorer-standalone.css');
    }
}

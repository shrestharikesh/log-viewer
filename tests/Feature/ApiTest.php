<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Feature;

use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

final class ApiTest extends TestCase
{
    private function id(string $name): string
    {
        return LogFile::makeIdentifier('local', $this->fixture($name));
    }

    public function test_lists_files(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        $this->getJson(route('log-explorer.api.files'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['identifier', 'name', 'size', 'size_human', 'last_modified']]]);
    }

    public function test_tail_endpoint_returns_last_lines(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 200);

        $this->getJson(route('log-explorer.api.tail', ['file' => $this->id('app.log'), 'lines' => 5]))
            ->assertOk()
            ->assertJsonCount(5, 'lines')
            ->assertJsonPath('meta.reached_end', true);
    }

    public function test_view_endpoint_paginates_with_cursor(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 100);

        $first = $this->getJson(route('log-explorer.api.view', ['file' => $this->id('app.log'), 'limit' => 10]))
            ->assertOk()
            ->assertJsonCount(10, 'lines')
            ->json();

        $this->assertNotNull($first['cursor']['next']);

        $this->getJson(route('log-explorer.api.view', [
            'file' => $this->id('app.log'), 'limit' => 10, 'cursor' => $first['cursor']['next'],
        ]))->assertOk()->assertJsonCount(10, 'lines');
    }

    public function test_search_endpoint_finds_matches(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 100);

        $this->getJson(route('log-explorer.api.search', [
            'file' => $this->id('app.log'), 'q' => 'Message number 42',
        ]))->assertOk()->assertJsonPath('meta.count', 1);
    }

    public function test_search_filters_by_level(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 100);

        $res = $this->getJson(route('log-explorer.api.search', [
            'file' => $this->id('app.log'), 'q' => '', 'level' => 'error',
        ]))->assertOk()->json();

        foreach ($res['matches'] as $m) {
            $this->assertSame('error', $m['parsed']['level']);
        }
        $this->assertGreaterThan(0, $res['meta']['count']);
    }

    public function test_search_endpoint_returns_newest_first_with_direction_backward(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 100);

        $res = $this->getJson(route('log-explorer.api.search', [
            'file' => $this->id('app.log'), 'q' => 'testing.ERROR', 'direction' => 'backward', 'limit' => 3,
        ]))->assertOk()
            ->assertJsonPath('meta.direction', 'backward')
            ->assertJsonPath('meta.count', 3)
            ->json();

        // ERROR is every 5th line from index 3: the newest three are 98, 93, 88.
        $this->assertStringContainsString('Message number 98 ', $res['matches'][0]['raw']);
        $this->assertStringContainsString('Message number 93 ', $res['matches'][1]['raw']);
        $this->assertStringContainsString('Message number 88 ', $res['matches'][2]['raw']);
        $this->assertNotNull($res['cursor']['next']);

        $next = $this->getJson(route('log-explorer.api.search', [
            'file' => $this->id('app.log'), 'q' => 'testing.ERROR', 'direction' => 'backward', 'limit' => 3,
            'cursor' => $res['cursor']['next'],
        ]))->assertOk()->json();

        $this->assertStringContainsString('Message number 83 ', $next['matches'][0]['raw']);
    }

    public function test_search_endpoint_reports_the_seeked_range(): void
    {
        $t0 = (int) strtotime('2026-03-01 00:00:00');
        $offsets = FixtureGenerator::timed($this->fixture('app.log'), array_map(fn ($i) => $t0 + $i * 3600, range(0, 99)));

        $this->getJson(route('log-explorer.api.search', [
            'file' => $this->id('app.log'), 'q' => 'Event', 'from' => date('Y-m-d H:i:s', $t0 + 90 * 3600),
        ]))->assertOk()
            ->assertJsonPath('meta.count', 10)
            ->assertJsonPath('meta.range.start', $offsets[90]);
    }

    public function test_count_endpoint_returns_buckets(): void
    {
        $t0 = (int) strtotime('2026-03-01 00:00:00');
        FixtureGenerator::timed($this->fixture('app.log'), array_map(fn ($i) => $t0 + $i * 60, range(0, 119)));

        $this->getJson(route('log-explorer.api.count', [
            'file' => $this->id('app.log'), 'q' => 'testing.ERROR', 'bucket' => 'hour',
        ]))->assertOk()
            ->assertJsonPath('bucket', 'hour')
            ->assertJsonPath('total', 24)
            ->assertJsonPath('buckets', ['2026-03-01 00:00' => 12, '2026-03-01 01:00' => 12])
            ->assertJsonPath('meta.complete', true)
            ->assertJsonPath('first', date('c', $t0 + 3 * 60))
            ->assertJsonPath('files.0.total', 24);
    }

    public function test_count_endpoint_rejects_an_unknown_bucket(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 10);

        $this->getJson(route('log-explorer.api.count', [
            'file' => $this->id('app.log'), 'q' => 'x', 'bucket' => 'fortnight',
        ]))->assertStatus(422);
    }

    public function test_count_endpoint_rejects_a_newest_first_cursor(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 10);
        $cursor = (new \Vendor\LogExplorer\Support\ByteCursor(100, 'x'))->encode();

        $this->getJson(route('log-explorer.api.count', [
            'file' => $this->id('app.log'), 'q' => 'x', 'direction' => 'backward', 'cursor' => $cursor,
        ]))->assertStatus(422);
    }

    public function test_search_endpoint_answers_422_for_an_invalid_regex_on_a_cli_engine(): void
    {
        if ((new \Symfony\Component\Process\ExecutableFinder())->find('grep') === null) {
            $this->markTestSkipped('grep binary not available on this host.');
        }
        config()->set('log-explorer.search.mode', 'grep');
        FixtureGenerator::laravelLines($this->fixture('app.log'), 10);

        $this->getJson(route('log-explorer.api.search', [
            'file' => $this->id('app.log'), 'q' => 'Message (', 'regex' => 1,
        ]))->assertStatus(422);
    }

    public function test_unknown_file_is_rejected(): void
    {
        $this->getJson(route('log-explorer.api.view', ['file' => 'bogus']))->assertNotFound();
    }

    public function test_path_traversal_is_rejected(): void
    {
        // An identifier pointing outside the configured root must 404.
        $evil = LogFile::makeIdentifier('local', '/etc/passwd');
        $this->getJson(route('log-explorer.api.view', ['file' => $evil]))->assertNotFound();
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        file_put_contents($this->fixture('secret.key'), "private");
        $evil = LogFile::makeIdentifier('local', $this->fixture('secret.key'));
        $this->getJson(route('log-explorer.api.view', ['file' => $evil]))->assertNotFound();
    }
}

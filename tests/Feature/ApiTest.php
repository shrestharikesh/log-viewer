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

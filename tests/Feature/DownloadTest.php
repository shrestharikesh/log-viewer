<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Feature;

use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

final class DownloadTest extends TestCase
{
    private function id(string $name): string
    {
        return LogFile::makeIdentifier('local', $this->fixture($name));
    }

    public function test_small_file_downloads(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 10);

        $res = $this->get(route('log-explorer.api.download', ['file' => $this->id('app.log')]));
        $res->assertOk();
        $res->assertHeader('Content-Disposition', 'attachment; filename="app.log"');
        $this->assertStringContainsString('Message number 0', $res->streamedContent());
    }

    public function test_oversized_file_is_blocked(): void
    {
        config()->set('log-explorer.download.max_download_size_mb', 0); // block everything
        FixtureGenerator::laravelLines($this->fixture('app.log'), 10);

        $this->get(route('log-explorer.api.download', ['file' => $this->id('app.log')]))
            ->assertStatus(413);
    }

    public function test_warned_file_requires_confirmation(): void
    {
        config()->set('log-explorer.download.max_download_size_mb', 100);
        config()->set('log-explorer.download.warn_download_size_mb', 0); // warn on everything

        FixtureGenerator::laravelLines($this->fixture('app.log'), 10);
        $id = $this->id('app.log');

        $this->get(route('log-explorer.api.download', ['file' => $id]))->assertStatus(409);
        $this->get(route('log-explorer.api.download', ['file' => $id, 'confirm' => 1]))->assertOk();
    }

    public function test_tail_mode_download_bypasses_size_limit(): void
    {
        config()->set('log-explorer.download.max_download_size_mb', 0);
        FixtureGenerator::laravelLines($this->fixture('app.log'), 100);

        $res = $this->get(route('log-explorer.api.download', [
            'file' => $this->id('app.log'), 'mode' => 'tail', 'lines' => 5,
        ]));

        $res->assertOk();
        $body = $res->streamedContent();
        $this->assertStringContainsString('Message number 99', $body);
        $this->assertStringNotContainsString('Message number 0 ', $body);
    }
}

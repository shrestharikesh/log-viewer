<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Unit;

use Symfony\Component\Process\ExecutableFinder;
use Vendor\LogExplorer\Search\CommandLineSearcher;
use Vendor\LogExplorer\Search\SearchCriteria;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

/**
 * The engine must run the exact binary that available() vetted, by absolute
 * path, never a name re-resolved through whatever PATH the worker has.
 */
final class BinaryResolutionTest extends TestCase
{
    private function grepOrSkip(): string
    {
        $grep = (new ExecutableFinder())->find('grep', null, ['/usr/bin', '/bin']);
        if ($grep === null) {
            $this->markTestSkipped('grep is not installed.');
        }

        return $grep;
    }

    private function searcher(string $binary): CommandLineSearcher
    {
        return new CommandLineSearcher($this->source(), $this->records(), 'grep', $binary, true);
    }

    public function test_a_bare_name_runs_by_its_absolute_path(): void
    {
        $this->grepOrSkip();
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        $searcher = $this->searcher('grep');
        $binary = $searcher->command(new SearchCriteria(query: 'x'), ['-'], false)[0];

        $this->assertTrue($searcher->available($this->logFile('app.log')));
        $this->assertStringStartsWith('/', $binary);
        $this->assertSame('grep', basename($binary));
        $this->assertSame($searcher->resolvedBinary(), $binary);
    }

    public function test_a_binary_outside_path_but_in_a_known_dir_still_runs(): void
    {
        // PHP-FPM often runs with a minimal PATH. Before, available() found
        // the binary in an extra dir but the command used the bare name, so
        // every search failed instead of running (or falling back).
        $this->grepOrSkip();
        FixtureGenerator::laravelLines($this->fixture('app.log'), 50);

        $path = getenv('PATH');
        putenv('PATH=/nonexistent-log-explorer-test');

        try {
            $searcher = $this->searcher('grep');
            $this->assertTrue($searcher->available($this->logFile('app.log')));

            $result = $searcher->search($this->logFile('app.log'), new SearchCriteria(query: 'Message number 42'));
            $this->assertCount(1, $result->matches);
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function test_the_configured_absolute_path_is_the_file_that_runs(): void
    {
        $grep = $this->grepOrSkip();
        FixtureGenerator::laravelLines($this->fixture('app.log'), 20);

        $marker = $this->fixture('wrapper-ran');
        $wrapper = $this->fixture('fake-grep');
        file_put_contents($wrapper, "#!/bin/sh\ntouch '{$marker}'\nexec '{$grep}' \"\$@\"\n");
        chmod($wrapper, 0755);

        $searcher = $this->searcher($wrapper);
        $result = $searcher->search($this->logFile('app.log'), new SearchCriteria(query: 'Message number 7'));

        $this->assertSame($wrapper, $searcher->resolvedBinary());
        $this->assertFileExists($marker);
        $this->assertCount(1, $result->matches);
    }

    public function test_a_missing_binary_is_unavailable(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        $searcher = $this->searcher('definitely-not-a-real-binary-xyz');

        $this->assertNull($searcher->resolvedBinary());
        $this->assertFalse($searcher->available($this->logFile('app.log')));
    }

    public function test_a_configured_path_that_is_not_executable_is_unavailable(): void
    {
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);
        file_put_contents($this->fixture('not-executable'), "#!/bin/sh\n");
        chmod($this->fixture('not-executable'), 0644);

        $searcher = $this->searcher($this->fixture('not-executable'));

        $this->assertNull($searcher->resolvedBinary());
        $this->assertFalse($searcher->available($this->logFile('app.log')));
    }
}

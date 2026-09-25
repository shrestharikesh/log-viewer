<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests;

use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use Vendor\LogExplorer\LogExplorerServiceProvider;
use Vendor\LogExplorer\Parsing\JsonLogParser;
use Vendor\LogExplorer\Parsing\LaravelLogParser;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Reading\LineScanner;
use Vendor\LogExplorer\Reading\RecordAssembler;
use Vendor\LogExplorer\Sources\LocalLogSource;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Support\PathValidator;

abstract class TestCase extends Orchestra
{
    protected string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Allow by default in tests; AuthorizationTest overrides these.
        Gate::define('viewLogs', fn ($user = null) => true);
        Gate::define('downloadLogs', fn ($user = null) => true);
    }

    /**
     * Runs before the package provider boots, so route registration sees the
     * temp source path and a test-friendly middleware stack.
     */
    protected function defineEnvironment($app): void
    {
        $this->logDir = sys_get_temp_dir().'/log-explorer-tests-'.getmypid();
        @mkdir($this->logDir, 0777, true);

        $app['config']->set('log-explorer.sources.drivers.local.paths', [$this->logDir]);
        // Drop 'auth' so feature tests exercise the package's own gate/middleware
        // without standing up a full auth guard.
        $app['config']->set('log-explorer.routes.middleware', ['web']);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->logDir);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LogExplorerServiceProvider::class];
    }

    /** A LocalLogSource rooted at the temp dir, for unit tests. */
    protected function source(): LocalLogSource
    {
        $validator = new PathValidator(
            roots: [$this->logDir],
            allowedExtensions: ['log', 'txt'],
            hiddenPatterns: ['*.key', '.env*'],
        );

        return new LocalLogSource([$this->logDir], $validator);
    }

    protected function scanner(): LineScanner
    {
        return new LineScanner(chunkSize: 64, maxLineLength: 4096); // tiny chunk exercises boundary crossing
    }

    protected function parserManager(): ParserManager
    {
        return new ParserManager([
            'laravel' => new LaravelLogParser(),
            'json' => new JsonLogParser(),
        ]);
    }

    protected function records(): RecordAssembler
    {
        return new RecordAssembler($this->scanner(), $this->parserManager());
    }

    protected function fixture(string $name): string
    {
        return $this->logDir.'/'.$name;
    }

    protected function logFile(string $name): LogFile
    {
        return $this->source()->resolve(LogFile::makeIdentifier('local', $this->fixture($name)));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

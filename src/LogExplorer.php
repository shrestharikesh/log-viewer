<?php

declare(strict_types=1);

namespace Vendor\LogExplorer;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Vendor\LogExplorer\Contracts\LogParserInterface;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;
use Vendor\LogExplorer\Sources\LocalLogSource;
use Vendor\LogExplorer\Support\PathValidator;

/**
 * Central runtime registry and extension surface for the package.
 *
 * Apps interact with it via the LogExplorer facade:
 *
 *   LogExplorer::extendParser('apache', ApacheParser::class);
 *   LogExplorer::extendSource('ssh', fn ($cfg) => new SshLogSource($cfg));
 *   LogExplorer::authorizeUsing(fn ($user, $ability, $file) => $user?->isAdmin());
 */
class LogExplorer
{
    /** @var array<string, class-string<LogParserInterface>|LogParserInterface> */
    protected array $parsers = [];

    /** @var array<string, Closure(array):LogSourceInterface> */
    protected array $sourceFactories = [];

    /** @var array<string, LogSourceInterface> */
    protected array $resolvedSources = [];

    protected ?Closure $authorizeCallback = null;

    public function __construct(protected Container $app)
    {
        $this->registerDefaultSourceFactories();
    }

    /* --------------------------------------------------------------------- */
    /* Parsers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * @param  class-string<LogParserInterface>|LogParserInterface  $parser
     */
    public function extendParser(string $name, string|LogParserInterface $parser): static
    {
        $this->parsers[$name] = $parser;

        return $this;
    }

    /**
     * Build a ParserManager honouring config order + runtime extensions.
     */
    public function parserManager(): ParserManager
    {
        $config = (array) $this->app['config']->get('log-explorer.parsing.parsers', []);

        $manager = new ParserManager(
            [],
            (bool) $this->app['config']->get('log-explorer.parsing.fallback_to_plain', true),
        );

        foreach ([...$config, ...$this->parsers] as $name => $parser) {
            $manager->register((string) $name, $this->resolveParser($parser));
        }

        return $manager;
    }

    protected function resolveParser(string|LogParserInterface $parser): LogParserInterface
    {
        return $parser instanceof LogParserInterface ? $parser : $this->app->make($parser);
    }

    /* --------------------------------------------------------------------- */
    /* Sources                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Register a driver factory for a future/custom source (ssh, s3, ...).
     *
     * @param  Closure(array):LogSourceInterface  $factory
     */
    public function extendSource(string $driver, Closure $factory): static
    {
        $this->sourceFactories[$driver] = $factory;

        return $this;
    }

    public function source(?string $driver = null): LogSourceInterface
    {
        $driver ??= (string) $this->app['config']->get('log-explorer.sources.default', 'local');

        if (isset($this->resolvedSources[$driver])) {
            return $this->resolvedSources[$driver];
        }

        $config = (array) $this->app['config']->get("log-explorer.sources.drivers.{$driver}");
        if ($config === []) {
            throw new InvalidArgumentException("Log source driver [{$driver}] is not configured.");
        }

        $factoryKey = $config['driver'] ?? $driver;
        if (! isset($this->sourceFactories[$factoryKey])) {
            throw new InvalidArgumentException("No factory registered for log source driver [{$factoryKey}].");
        }

        return $this->resolvedSources[$driver] = ($this->sourceFactories[$factoryKey])($config);
    }

    protected function registerDefaultSourceFactories(): void
    {
        $this->sourceFactories['local'] = function (array $config): LocalLogSource {
            $files = (array) $this->app['config']->get('log-explorer.files');
            $roots = array_values(array_filter((array) ($config['paths'] ?? [])));

            $validator = new PathValidator(
                roots: $roots,
                allowedExtensions: (array) ($files['allowed_extensions'] ?? []),
                hiddenPatterns: (array) ($files['hidden'] ?? []),
                showHiddenDotfiles: (bool) ($files['show_hidden_dotfiles'] ?? false),
            );

            return new LocalLogSource($roots, $validator);
        };
    }

    /* --------------------------------------------------------------------- */
    /* Authorization                                                          */
    /* --------------------------------------------------------------------- */

    /**
     * @param  Closure(mixed,string,mixed):bool  $callback
     */
    public function authorizeUsing(Closure $callback): static
    {
        $this->authorizeCallback = $callback;

        return $this;
    }

    public function authorizationCallback(): ?Closure
    {
        return $this->authorizeCallback;
    }
}

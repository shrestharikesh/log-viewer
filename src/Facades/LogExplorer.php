<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Facades;

use Illuminate\Support\Facades\Facade;
use Vendor\LogExplorer\Contracts\LogParserInterface;
use Vendor\LogExplorer\Contracts\LogSourceInterface;
use Vendor\LogExplorer\Parsing\ParserManager;

/**
 * @method static \Vendor\LogExplorer\LogExplorer extendParser(string $name, string|LogParserInterface $parser)
 * @method static \Vendor\LogExplorer\LogExplorer extendSource(string $driver, \Closure $factory)
 * @method static \Vendor\LogExplorer\LogExplorer authorizeUsing(\Closure $callback)
 * @method static LogSourceInterface source(?string $driver = null)
 * @method static ParserManager parserManager()
 *
 * @see \Vendor\LogExplorer\LogExplorer
 */
class LogExplorer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vendor\LogExplorer\LogExplorer::class;
    }
}

<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Vendor\LogExplorer\Support\LogFile;
use Vendor\LogExplorer\Tests\Support\FixtureGenerator;
use Vendor\LogExplorer\Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    public function test_view_is_denied_when_gate_denies(): void
    {
        Gate::define('viewLogs', fn ($user = null) => false);
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        $this->getJson(route('log-explorer.api.files'))->assertForbidden();
    }

    public function test_view_is_denied_by_default_when_gate_undefined_and_strict(): void
    {
        // Remove the permissive gate defined in the base TestCase.
        Gate::define('viewLogs', fn ($user = null) => null);
        config()->set('log-explorer.authorization.strict', true);

        // Re-point to a gate name that is genuinely undefined.
        config()->set('log-explorer.authorization.view_gate', 'someUndefinedAbility');

        $this->getJson(route('log-explorer.api.files'))->assertForbidden();
    }

    public function test_download_uses_separate_gate(): void
    {
        Gate::define('viewLogs', fn ($user = null) => true);
        Gate::define('downloadLogs', fn ($user = null) => false);
        FixtureGenerator::laravelLines($this->fixture('app.log'), 5);

        $id = LogFile::makeIdentifier('local', $this->fixture('app.log'));

        // Viewing still works...
        $this->getJson(route('log-explorer.api.tail', ['file' => $id]))->assertOk();
        // ...but downloading is forbidden.
        $this->get(route('log-explorer.api.download', ['file' => $id]))->assertForbidden();
    }
}

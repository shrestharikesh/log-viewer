<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Auth;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Vendor\LogExplorer\LogExplorer;
use Vendor\LogExplorer\Support\LogFile;

/**
 * Single decision point for "may this request view / download logs?".
 *
 * Secure by default: when `authorization.strict` is true and the relevant gate
 * is undefined, access is DENIED rather than silently allowed. A custom
 * callback (config or LogExplorer::authorizeUsing) takes precedence over gates.
 */
final class Authorizer
{
    public function __construct(
        private readonly Gate $gate,
        private readonly Config $config,
        private readonly LogExplorer $explorer,
    ) {
    }

    public function canView(mixed $user, ?LogFile $file = null): bool
    {
        return $this->authorize($user, (string) $this->config->get('log-explorer.authorization.view_gate'), $file);
    }

    public function canDownload(mixed $user, ?LogFile $file = null): bool
    {
        return $this->authorize($user, (string) $this->config->get('log-explorer.authorization.download_gate'), $file);
    }

    private function authorize(mixed $user, string $ability, ?LogFile $file): bool
    {
        // 1. Runtime callback wins.
        if ($callback = $this->explorer->authorizationCallback()) {
            return (bool) $callback($user, $ability, $file);
        }

        // 2. Config-declared callable.
        $configCallback = $this->config->get('log-explorer.authorization.callback');
        if (is_callable($configCallback)) {
            return (bool) $configCallback($user, $ability, $file);
        }

        // 3. Gate, with strict default-deny.
        if ($this->gate->has($ability)) {
            return $this->gate->forUser($user)->allows($ability, $file);
        }

        if ($this->config->get('log-explorer.authorization.strict', true)) {
            return false;
        }

        // Non-strict fallback: any authenticated user.
        return $user !== null;
    }
}

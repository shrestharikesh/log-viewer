<?php

declare(strict_types=1);

namespace Vendor\LogExplorer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vendor\LogExplorer\Auth\Authorizer;

/**
 * Gatekeeps every package route with the "view" ability, with no file (general
 * access). Per-file view access is checked where the file is resolved
 * (ResolvesLogFile) and in the file listing; download authorization is
 * additionally enforced in the download controller (the separate download
 * gate, against the specific file).
 */
final class Authorize
{
    public function __construct(private readonly Authorizer $authorizer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->authorizer->canView($request->user())) {
            abort(403, 'You are not authorized to view logs.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || $request->user()->is_suspended || !in_array($request->user()->role, $roles, true)) {
            return response()->json(['message' => 'Accès interdit.'], 403);
        }

        return $next($request);
    }
}

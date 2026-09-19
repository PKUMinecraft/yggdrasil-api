<?php

namespace Yggdrasil\Middleware;

use Closure;
use Illuminate\Http\Request;

class PremiumBridgeAuth
{
    public function handle(Request $request, Closure $next)
    {
        $path = storage_path('app/trusted-bridge-api.secret');
        $secret = is_readable($path) ? trim(file_get_contents($path)) : '';
        abort_unless(strlen($secret) >= 64 && hash_equals($secret, $request->bearerToken() ?? ''), 401);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}

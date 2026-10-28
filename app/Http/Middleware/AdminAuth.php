<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin panel: X-Admin-Key sarlavhasi .env dagi ADMIN_KEY bilan solishtiriladi. */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('services.admin.key');
        $given = (string) $request->header('X-Admin-Key', '');
        if ($key === '' || ! hash_equals($key, $given)) {
            return response()->json(['message' => 'Admin kaliti noto\'g\'ri'], 403);
        }

        return $next($request);
    }
}

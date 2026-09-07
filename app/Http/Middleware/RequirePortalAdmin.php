<?php

namespace App\Http\Middleware;

use App\Services\CognitoDirectory;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;

class RequirePortalAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->session()->get('auth.status.authenticated')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your session expired. Please sign in again.'], 401);
            }

            return redirect()->route('portal.login');
        }
        $username = $request->session()->get('auth.status.user.username');
        abort_unless(is_string($username) && $username !== '', 403);
        try {
            $directory = app(CognitoDirectory::class);
            $user = $directory->get($username);
            $allowed = $user && $directory->isAdministrator($user);
        } catch (RuntimeException) {
            abort(503, 'Administrator access could not be verified. Please try again.');
        }
        abort_unless($allowed, 403);

        return $next($request);
    }
}

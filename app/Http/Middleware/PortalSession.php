<?php

namespace App\Http\Middleware;

use App\Services\CognitoIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('auth.status.authenticated')) {
            $tokens = $request->session()->get('auth.tokens', []);
            if (($tokens['expires_at'] ?? 0) <= time() + 60) {
                try {
                    $result = app(CognitoIdentityService::class)->refresh($tokens);
                    $request->session()->put('auth.tokens', $result['tokens']);
                    $request->session()->put('auth.status', ['authenticated' => true, 'user' => $result['user']]);
                } catch (Throwable) {
                    $request->session()->forget(['auth.status', 'auth.tokens']);
                    $request->session()->regenerate();
                }
            }
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}

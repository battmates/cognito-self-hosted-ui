<?php

use App\Exceptions\OAuthException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['state', 'code', 'code_verifier', 'code_challenge', 'client_secret', 'client_id', 'redirect_uri', 'logout_uri']);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->has('state')]);
        $middleware->validateCsrfTokens(except: ['oauth2/token', 'webhooks/ses-events']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'client_secret', 'code_verifier']);
        $exceptions->render(function (OAuthException $exception, Request $request) {
            if ($request->is('oauth2/token')) {
                return response()->json(['error' => $exception->error, 'error_description' => $exception->getMessage()], $exception->status)
                    ->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
            }

            return response()->view('portal.error', ['message' => $exception->getMessage()], $exception->status)->header('Cache-Control', 'no-store');
        });
        $exceptions->render(function (ConnectionException $exception, Request $request) {
            return response()->view('portal.error', ['message' => 'The sign-in service is temporarily unavailable. Please try again.'], 503)->header('Cache-Control', 'no-store');
        });
    })->create();

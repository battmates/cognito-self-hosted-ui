<?php

namespace App\Http\Controllers;

use App\Services\AuthorizationBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuthController extends Controller
{
    public function token(Request $request, AuthorizationBroker $broker): JsonResponse
    {
        return response()->json($broker->exchange($request))->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}

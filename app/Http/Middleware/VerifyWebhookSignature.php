<?php

namespace App\Http\Middleware;

use App\Models\Call;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Call::hasValidSignature($request->getContent(), $request->header('X-Signature'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}

<?php

namespace Dashed\DashedEcommerceRendabiliteit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token voor de advertentie-API (`ADVERTENTIE_API_TOKEN`, config dashed-ecommerce-rendabiliteit.api_token); zonder ingesteld token staat de API dicht. */
class AdvertentieApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $verwacht = (string) config('dashed-ecommerce-rendabiliteit.api_token');

        if ($verwacht === '' || ! hash_equals($verwacht, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}

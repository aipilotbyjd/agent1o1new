<?php

namespace App\Http\Middleware;

use App\Http\Response\ApiResponse;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyEngineCallbackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-LinkFlow-Timestamp');
        $signature = $request->header('X-LinkFlow-Signature');

        if (! $timestamp || ! $signature) {
            return ApiResponse::error('Missing signature headers.')->send(403);
        }

        try {
            $parsedTime = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return ApiResponse::error('Invalid timestamp format.')->send(403);
        }

        $ttl = (int) config('services.engine.callback_ttl', 300);

        if (abs(now()->diffInSeconds($parsedTime)) > $ttl) {
            return ApiResponse::error('Callback timestamp expired.')->send(403);
        }

        $secret = config('services.engine.secret');

        if (empty($secret) && app()->runningUnitTests()) {
            $secret = 'test-engine-secret';
        }

        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (! hash_equals($expected, $signature)) {
            return ApiResponse::error('Invalid signature.')->send(403);
        }

        return $next($request);
    }
}

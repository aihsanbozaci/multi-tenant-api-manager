<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api automatically by Laravel's routing
| bootstrap (api: __DIR__.'/../routes/api.php' in bootstrap/app.php).
|
| The 'api.gateway' middleware alias resolves to:
|   App\Domain\Api\Gateway\Http\Middleware\ApiGatewayGuardMiddleware
|
| Every route inside this group requires a valid X-API-KEY header. The
| middleware enforces authentication, per-key rate limiting, and async
| usage logging — all without touching MySQL on the hot path.
|
*/

Route::middleware('api.gateway')->group(function (): void {

    /**
     * GET /api/health
     *
     * Lightweight liveness probe protected by the gateway guard.
     * Useful for verifying that the full auth + rate-limit pipeline
     * is operational end-to-end.
     *
     * Response 200: {"status": "ok", "timestamp": "<ISO8601>"}
     * Response 401: missing or unknown API key.
     * Response 429: per-key rate limit exceeded.
     */
    Route::get('/health', static function (): \Illuminate\Http\JsonResponse {
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    })->name('api.health');

});

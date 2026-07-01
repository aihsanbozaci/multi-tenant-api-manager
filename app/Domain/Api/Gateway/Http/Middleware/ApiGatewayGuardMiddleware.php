<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Http\Middleware;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Data\ApiUsageLogEntry;
use App\Domain\Api\Gateway\Data\RateLimitResult;
use App\Domain\Api\Gateway\Jobs\ProcessApiUsageLogs;
use App\Domain\Api\Gateway\Services\ApiKeyCacheService;
use App\Domain\Api\Gateway\Services\SlidingWindowRateLimiter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hot-path API Gateway authentication, rate-limiting, and async logging middleware.
 *
 * Terminable: Laravel's HTTP kernel detects the terminate() method and calls it
 * automatically AFTER the response has been sent to the client. No interface
 * implementation needed — the method presence is sufficient.
 *
 * handle() flow (synchronous, on every request):
 *   1. Extract raw token from X-API-KEY header → 401 if missing.
 *   2. SHA-256 hash the token → Redis HGETALL → 401 on miss or revoked.
 *   3. Sliding-window rate limit check → 429 + standard headers on denial.
 *   4. Stamp request attributes for terminate() (tenant_id, api_key_id, start_ns).
 *   5. Forward request; append X-RateLimit-* headers to response.
 *
 * terminate() flow (asynchronous, post-response):
 *   1. Read stamped attributes; bail early if auth failed.
 *   2. Compute response_time_ms via hrtime() nanosecond delta.
 *   3. Dispatch ProcessApiUsageLogs to the analytics queue — ZERO MySQL I/O here.
 *
 * Hot-path rule: this middleware NEVER queries MySQL. All decisions are made
 * from data already in Redis. A Redis miss === 401 (no DB fallback).
 */
final class ApiGatewayGuardMiddleware
{
    // -----------------------------------------------------------------------
    // Request attribute keys passed from handle() to terminate()
    // -----------------------------------------------------------------------

    /** @var string Attribute key for the authenticated tenant UUID. */
    private const string ATTR_TENANT_ID = 'api_gateway.tenant_id';

    /** @var string Attribute key for the authenticated API key bigint PK. */
    private const string ATTR_API_KEY_ID = 'api_gateway.api_key_id';

    /**
     * @var string Attribute key for the request start timestamp in nanoseconds.
     *             Set via hrtime(true) which is monotonic and immune to NTP jumps.
     */
    private const string ATTR_STARTED_AT_NS = 'api_gateway.started_at_ns';

    public function __construct(
        private readonly GatewayConfig $config,
        private readonly ApiKeyCacheService $cacheService,
        private readonly SlidingWindowRateLimiter $rateLimiter,
    ) {}

    // -----------------------------------------------------------------------
    // Middleware handle — synchronous, hot path
    // -----------------------------------------------------------------------

    /**
     * Authenticate, rate-limit, and forward the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // -------------------------------------------------------------------
        // Step 1 — Extract the raw API key token from the configured header.
        // -------------------------------------------------------------------
        $rawToken = $request->header($this->config->apiKeyHeader);

        if (!is_string($rawToken) || $rawToken === '') {
            return $this->unauthorizedJson(
                'missing_api_key',
                sprintf('The %s header is required.', $this->config->apiKeyHeader)
            );
        }

        // -------------------------------------------------------------------
        // Step 2 — Hash the token and look up the payload in Redis (HGETALL).
        //          Single round-trip; NO MySQL query on this path.
        // -------------------------------------------------------------------
        $keyHash = hash('sha256', $rawToken);
        $payload = $this->cacheService->findByHash($keyHash);

        if ($payload === null || !$payload->isActive()) {
            return $this->unauthorizedJson(
                'invalid_api_key',
                'The provided API key is invalid, expired, or has been revoked.'
            );
        }

        // -------------------------------------------------------------------
        // Step 3 — Sliding-window rate limit check (atomic Lua EVAL).
        //          Denied requests return 429 immediately; NO MySQL I/O.
        // -------------------------------------------------------------------
        $rateLimitResult = $this->rateLimiter->attempt(
            tenantId:      $payload->tenantId,
            apiKeyId:      $payload->apiKeyId,
            limit:         $payload->rateLimitMax,
            windowSeconds: $payload->rateLimitWindow,
        );

        if (!$rateLimitResult->allowed) {
            return $this->rateLimitExceededJson($rateLimitResult);
        }

        // -------------------------------------------------------------------
        // Step 4 — Stamp request attributes so terminate() can build the log
        //          entry without needing to re-authenticate or re-query Redis.
        // -------------------------------------------------------------------
        $request->attributes->set(self::ATTR_TENANT_ID,     $payload->tenantId);
        $request->attributes->set(self::ATTR_API_KEY_ID,     $payload->apiKeyId);
        $request->attributes->set(self::ATTR_STARTED_AT_NS,  hrtime(true));

        // -------------------------------------------------------------------
        // Step 5 — Forward the request and decorate the response with
        //          rate-limit telemetry headers on every successful request.
        // -------------------------------------------------------------------
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit',     (string) $rateLimitResult->limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $rateLimitResult->remaining);

        return $response;
    }

    // -----------------------------------------------------------------------
    // Terminable — called AFTER response is sent to the client
    // -----------------------------------------------------------------------

    /**
     * Dispatch the async usage log job after the HTTP lifecycle completes.
     *
     * Laravel's kernel calls this method automatically when the middleware has a
     * terminate() signature, AFTER $response->send() has been called. The client
     * already has the response by the time this code runs.
     *
     * This means:
     *   - Zero latency impact on the client.
     *   - ProcessApiUsageLogs is queued (not executed) here — actual MySQL
     *     writes happen in the background worker.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Bail early if handle() did not stamp the request (auth failed path).
        $tenantId  = $request->attributes->get(self::ATTR_TENANT_ID);
        $apiKeyId  = $request->attributes->get(self::ATTR_API_KEY_ID);
        $startedNs = $request->attributes->get(self::ATTR_STARTED_AT_NS);

        if (!is_string($tenantId) || !is_int($apiKeyId) || !is_int($startedNs)) {
            return;
        }

        // Nanosecond delta → milliseconds. hrtime() is monotonic so this is
        // immune to DST changes, NTP corrections, and leap seconds.
        $responseTimeMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);

        // getContent() returns false for StreamedResponse; treat as 0 bytes.
        $payloadSizeBytes = (int) strlen((string) ($response->getContent() ?: ''));

        $entry = new ApiUsageLogEntry(
            tenantId:         $tenantId,
            apiKeyId:         $apiKeyId,
            endpoint:         $request->getPathInfo(),
            method:           strtoupper($request->method()),
            statusCode:       $response->getStatusCode(),
            responseTimeMs:   max(0, $responseTimeMs),
            payloadSizeBytes: max(0, $payloadSizeBytes),
            requestedAt:      CarbonImmutable::now(),
        );

        // Dispatch to the dedicated analytics queue.
        // The job accumulates in the Redis buffer; MySQL is NOT touched here.
        ProcessApiUsageLogs::dispatch($entry)
            ->onConnection($this->config->queueConnection)
            ->onQueue($this->config->analyticsQueue);
    }

    // -----------------------------------------------------------------------
    // Private response factories
    // -----------------------------------------------------------------------

    /**
     * Returns a 401 JSON response with a machine-readable error code.
     */
    private function unauthorizedJson(string $error, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error'   => $error,
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Returns a 429 JSON response with standard rate-limit headers.
     *
     * Header semantics (RFC 6585 + draft-polli-ratelimit-headers):
     *   X-RateLimit-Limit     : configured ceiling for this key.
     *   X-RateLimit-Remaining : always 0 when the limit is exceeded.
     *   Retry-After           : seconds until the oldest window entry expires.
     */
    private function rateLimitExceededJson(RateLimitResult $result): JsonResponse
    {
        return response()->json(
            [
                'message' => 'Too Many Requests. Please wait and retry.',
                'error'   => 'rate_limit_exceeded',
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            [
                'X-RateLimit-Limit'     => (string) $result->limit,
                'X-RateLimit-Remaining' => '0',
                'Retry-After'           => (string) $result->retryAfterSeconds,
            ]
        );
    }
}

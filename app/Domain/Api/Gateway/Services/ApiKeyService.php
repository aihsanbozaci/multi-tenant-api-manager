<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Services;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Contracts\ApiKeyRepositoryInterface;
use App\Domain\Api\Gateway\Data\ApiKeyCachePayload;
use App\Domain\Api\Gateway\Data\CreatedApiKeyResult;
use App\Domain\Api\Gateway\Enums\ApiKeyStatus;
use App\Domain\Api\Gateway\Models\ApiKey;
use App\Domain\Api\Gateway\Support\TokenGenerator;
use Carbon\CarbonImmutable;

/**
 * High-level orchestration service for API key lifecycle management.
 *
 * Responsibilities:
 *   - Token generation and secure hashing (via TokenGenerator).
 *   - Atomic DB insert + Redis cache warm-up on key creation.
 *   - Key revocation with immediate cache invalidation.
 *   - Explicit cache refresh and invalidation helpers for admin operations.
 *
 * Hot-path rule: this service is NEVER called during request authentication.
 * Authentication reads exclusively from Redis via ApiKeyCacheService.
 * This service handles write-path operations only (key management).
 *
 * Observer interaction:
 *   EloquentApiKeyRepository::updateStatus() loads the model and calls
 *   save() so Eloquent fires the 'updated' event → ApiKeyObserver syncs Redis.
 *   ApiKeyService::revoke() relies on this chain; no explicit cache call needed.
 *
 *   ApiKeyService::create() does an EXPLICIT warm-up after DB insert because:
 *     a) The plain token is only available at creation time (used for payload).
 *     b) We want the cache hot before returning the token to the caller.
 *   The Observer's 'created' handler also fires (idempotent double write).
 */
final class ApiKeyService
{
    /**
     * Default cache TTL when a key has no expiry (seconds).
     * 24 hours; the Observer resets this TTL on every key update.
     */
    private const DEFAULT_CACHE_TTL_SECONDS = 86_400;

    /**
     * Minimum cache TTL to avoid storing near-expired entries (seconds).
     */
    private const MINIMUM_CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly GatewayConfig $config,
        private readonly TokenGenerator $tokenGenerator,
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly ApiKeyCacheService $cacheService,
    ) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Create a new API key for the given tenant.
     *
     * Execution order:
     *   1. Generate cryptographically secure token (CSPRNG).
     *   2. Persist key_id (plain) and key_hash (SHA-256) to MySQL.
     *   3. Warm up the Redis hash cache with rate-limit data and TTL.
     *   4. Return CreatedApiKeyResult containing the ONE-TIME plain token.
     *
     * The plain token is never stored. After this call returns, it cannot
     * be recovered — the tenant must save it immediately.
     *
     * @param  string              $tenantId       UUID of the owning tenant.
     * @param  string              $name           Human-readable label.
     * @param  int                 $rateLimitMax   Max requests per window.
     * @param  int                 $rateLimitWindow Window size in seconds.
     * @param  CarbonImmutable|null $expiresAt     Null = never expires.
     *
     * @throws \Throwable  Propagates DB or Redis failures to the caller.
     */
    public function create(
        string $tenantId,
        string $name,
        int $rateLimitMax,
        int $rateLimitWindow,
        ?CarbonImmutable $expiresAt = null,
    ): CreatedApiKeyResult {
        // Step 1: Generate a secure token with its derived identifiers.
        $generated = $this->tokenGenerator->generate();

        // Step 2: Persist to MySQL. The Eloquent 'created' event fires here,
        // triggering ApiKeyObserver::created() for a secondary cache warm-up
        // (idempotent; the explicit warm-up below is the authoritative one).
        $apiKeyId = $this->apiKeyRepository->create(
            tenantId:        $tenantId,
            name:            $name,
            keyId:           $generated->keyId,
            keyHash:         $generated->keyHash,
            rateLimitMax:    $rateLimitMax,
            rateLimitWindow: $rateLimitWindow,
            expiresAt:       $expiresAt,
        );

        // Step 3: Explicitly warm up Redis. This runs AFTER the DB insert so we
        // have a valid $apiKeyId. We build the payload directly here because we
        // have all fields in scope without an extra DB query.
        $payload = new ApiKeyCachePayload(
            tenantId:        $tenantId,
            apiKeyId:        $apiKeyId,
            rateLimitMax:    $rateLimitMax,
            rateLimitWindow: $rateLimitWindow,
            status:          ApiKeyStatus::Active,
        );

        $this->cacheService->put(
            $generated->keyHash,
            $payload,
            $this->calculateTtl($expiresAt)
        );

        // Step 4: Return the result. plainToken is the only field the caller
        // receives that is never recoverable — it must be stored by the tenant.
        return new CreatedApiKeyResult(
            apiKeyId:   $apiKeyId,
            plainToken: $generated->plainToken,
            keyId:      $generated->keyId,
            keyHash:    $generated->keyHash,
        );
    }

    /**
     * Permanently revoke an API key.
     *
     * Updates the MySQL status to 'revoked', which triggers the Eloquent
     * 'updated' event → ApiKeyObserver::updated() → Redis DEL.
     * The key will return 401 on the next request (Redis miss → fail 401).
     *
     * @param  int  $apiKeyId  Primary key of the api_keys row to revoke.
     */
    public function revoke(int $apiKeyId): void
    {
        // Repository loads the model + calls save() to fire Eloquent events.
        // Observer handles Redis invalidation synchronously in the same request.
        $this->apiKeyRepository->updateStatus($apiKeyId, ApiKeyStatus::Revoked);
    }

    /**
     * Explicitly refresh the Redis cache for an already-loaded ApiKey model.
     *
     * Useful for admin operations where the model is already in scope (e.g.
     * after a rate-limit update). Builds the payload from model attributes
     * and calls ApiKeyCacheService::put() with a recalculated TTL.
     *
     * @param  ApiKey  $apiKey  The model instance to read from.
     */
    public function refreshCache(ApiKey $apiKey): void
    {
        $status = $apiKey->status instanceof ApiKeyStatus
            ? $apiKey->status
            : ApiKeyStatus::from((string) $apiKey->status);

        $payload = new ApiKeyCachePayload(
            tenantId:        (string) $apiKey->tenant_id,
            apiKeyId:        (int)    $apiKey->id,
            rateLimitMax:    (int)    $apiKey->rate_limit_max,
            rateLimitWindow: (int)    $apiKey->rate_limit_window,
            status:          $status,
        );

        $expiresAt = $apiKey->expires_at instanceof CarbonImmutable
            ? $apiKey->expires_at
            : null;

        $this->cacheService->put(
            (string) $apiKey->key_hash,
            $payload,
            $this->calculateTtl($expiresAt)
        );
    }

    /**
     * Immediately remove a cache entry by its key hash.
     *
     * For callers that already have the key_hash in scope (e.g. token rotation
     * flows, admin invalidation endpoints). The Observer's 'updated'/'deleted'
     * path calls this indirectly via ApiKeyCacheService::forget().
     *
     * @param  string  $keyHash  64-char SHA-256 hex digest of the plain token.
     */
    public function invalidateCache(string $keyHash): void
    {
        $this->cacheService->forget($keyHash);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Compute the Redis TTL for a given expiry timestamp.
     *
     * Rules:
     *   null (no expiry)          → DEFAULT_CACHE_TTL_SECONDS (24 h)
     *   expiresAt in the future   → seconds remaining, floored at MINIMUM_CACHE_TTL_SECONDS
     *   expiresAt in the past     → MINIMUM_CACHE_TTL_SECONDS (safety net for race conditions)
     */
    private function calculateTtl(?CarbonImmutable $expiresAt): int
    {
        if ($expiresAt === null) {
            return self::DEFAULT_CACHE_TTL_SECONDS;
        }

        $secondsRemaining = (int) now()->diffInRealSeconds($expiresAt, absolute: false);

        return max($secondsRemaining, self::MINIMUM_CACHE_TTL_SECONDS);
    }
}

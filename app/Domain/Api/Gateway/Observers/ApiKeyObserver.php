<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Observers;

use App\Domain\Api\Gateway\Data\ApiKeyCachePayload;
use App\Domain\Api\Gateway\Enums\ApiKeyStatus;
use App\Domain\Api\Gateway\Models\ApiKey;
use App\Domain\Api\Gateway\Services\ApiKeyCacheService;
use Carbon\CarbonImmutable;

/**
 * Eloquent observer for the ApiKey model.
 *
 * Responsibility: keep the Redis API key cache in sync with the MySQL source
 * of truth whenever a key is created, updated, or deleted.
 *
 * Registration: GatewayServiceProvider::boot() resolves the model class from
 * config and calls ::observe(ApiKeyObserver::class) — no hard dependency on
 * the concrete ApiKey class inside the provider.
 *
 * Design notes:
 *  - Depends on ApiKeyCacheService (not ApiKeyService) to avoid a circular
 *    dependency: ApiKeyService → Repository → Model → Observer → ApiKeyService.
 *  - TTL calculation mirrors ApiKeyService::refreshCache() so both paths
 *    produce identical cache expiry behaviour.
 *  - All observer methods are idempotent: Redis HSET / DEL called multiple
 *    times for the same key produces the same final state.
 *  - Uses the typed ApiKey model rather than a generic Model so we get full
 *    IDE support and static analysis on model attributes.
 */
class ApiKeyObserver
{
    /**
     * Default cache TTL in seconds when no expires_at is set.
     * 24 hours balances freshness with Redis memory usage.
     */
    private const DEFAULT_TTL_SECONDS = 86_400;

    /**
     * Minimum TTL in seconds to prevent storing near-expired entries that
     * would be stale before the next worker cycle.
     */
    private const MINIMUM_TTL_SECONDS = 60;

    public function __construct(
        private readonly ApiKeyCacheService $cacheService,
    ) {}

    // -----------------------------------------------------------------------
    // Observer event handlers
    // -----------------------------------------------------------------------

    /**
     * Warm up the Redis cache when a new API key is persisted.
     *
     * Note: ApiKeyService::create() also performs an explicit warm-up after
     * insert. This handler covers the case where a key is created via any
     * other code path (e.g. seeders, admin commands, tests).
     * Redis HSET is idempotent so double warm-up is harmless.
     */
    public function created(ApiKey $apiKey): void
    {
        $this->warmUpCache($apiKey);
    }

    /**
     * Sync the Redis cache when an API key record is updated.
     *
     * Invalidation conditions (DEL the cache entry):
     *   1. status changed to 'revoked' — key must stop authenticating immediately.
     *   2. expires_at is now in the past — expired key must stop authenticating.
     *
     * In all other cases, refresh the cache with the latest field values and
     * a recalculated TTL.
     */
    public function updated(ApiKey $apiKey): void
    {
        // Invalidate immediately if the key has been revoked.
        if ($apiKey->status === ApiKeyStatus::Revoked) {
            $this->cacheService->forget($apiKey->key_hash);
            return;
        }

        // Invalidate if the key is now expired (e.g. expires_at was backdated).
        if ($apiKey->isExpired()) {
            $this->cacheService->forget($apiKey->key_hash);
            return;
        }

        // Key is still active — refresh the cached payload.
        $this->warmUpCache($apiKey);
    }

    /**
     * Invalidate the Redis cache when an API key is hard-deleted.
     *
     * Hard deletes are administrative only (EloquentApiKeyRepository::delete()).
     * Normal revocation uses updateStatus() + 'updated' event path above.
     */
    public function deleted(ApiKey $apiKey): void
    {
        $this->cacheService->forget($apiKey->key_hash);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build an ApiKeyCachePayload from the model and store it in Redis.
     * Calculates a TTL that ensures the cache entry naturally expires when
     * the key's own expires_at is reached.
     */
    private function warmUpCache(ApiKey $apiKey): void
    {
        $payload = new ApiKeyCachePayload(
            tenantId:        (string) $apiKey->tenant_id,
            apiKeyId:        (int)    $apiKey->id,
            rateLimitMax:    (int)    $apiKey->rate_limit_max,
            rateLimitWindow: (int)    $apiKey->rate_limit_window,
            status:          $apiKey->status instanceof ApiKeyStatus
                                 ? $apiKey->status
                                 : ApiKeyStatus::from((string) $apiKey->status),
        );

        $ttl = $this->calculateTtl($apiKey);

        $this->cacheService->put($apiKey->key_hash, $payload, $ttl);
    }

    /**
     * Calculates the Redis TTL for a given API key.
     *
     * Rules:
     *  - No expires_at → DEFAULT_TTL_SECONDS (24 hours).
     *  - expires_at in the future → seconds until expiry, floored at MINIMUM_TTL_SECONDS.
     *  - expires_at in the past → MINIMUM_TTL_SECONDS (should have been invalidated,
     *    but we still set a short TTL as a safety fallback for race conditions).
     */
    private function calculateTtl(ApiKey $apiKey): int
    {
        if (!($apiKey->expires_at instanceof CarbonImmutable)) {
            return self::DEFAULT_TTL_SECONDS;
        }

        $secondsRemaining = (int) now()->diffInRealSeconds($apiKey->expires_at, absolute: false);

        return max($secondsRemaining, self::MINIMUM_TTL_SECONDS);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Services;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Data\ApiKeyCachePayload;
use App\Domain\Api\Gateway\Support\RedisKeyBuilder;
use Illuminate\Support\Facades\Redis;

/**
 * Low-level Redis cache service for API key lookup data.
 *
 * This is the ONLY class that performs Redis I/O for API key authentication.
 * It sits directly on the middleware hot path — every design decision here
 * optimises for sub-millisecond latency.
 *
 * Redis data structure: HASH at key api_keys:{sha256_hash}
 * Fields: tenant_id, api_key_id, rate_limit_max, rate_limit_window, status
 *
 * TTL strategy:
 *   - Keys with an expiry: TTL = seconds until expires_at (min 60s).
 *   - Keys without expiry: TTL = 86400s (24 hours, refreshed by Observer on update).
 *
 * Fail-open philosophy:
 *   If Redis is unavailable, findByHash() returns null which causes the
 *   middleware to return 401. This is intentional: we never fall back to
 *   MySQL on the hot path — a 401 is preferable to a slow DB query under load.
 */
final class ApiKeyCacheService
{
    public function __construct(
        private readonly GatewayConfig $config,
        private readonly RedisKeyBuilder $keyBuilder,
    ) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Look up a cached API key payload by the SHA-256 hash of the plain token.
     *
     * Performs a single HGETALL command. Returns null on cache miss or when
     * the Redis command fails — the middleware must treat null as 401.
     *
     * @param  string  $keyHash  64-char SHA-256 hex digest of the raw token.
     * @return ApiKeyCachePayload|null  Null on miss; valid payload on hit.
     */
    public function findByHash(string $keyHash): ?ApiKeyCachePayload
    {
        try {
            $redisKey = $this->keyBuilder->apiKeyHash($keyHash);

            /** @var array<string, string>|null $hash */
            $hash = Redis::connection($this->config->redisConnection)
                ->hgetall($redisKey);

            // HGETALL returns an empty array on a miss (key does not exist).
            if (empty($hash)) {
                return null;
            }

            return ApiKeyCachePayload::fromRedisHash($hash);
        } catch (\Throwable) {
            // Redis unavailable or data malformed — treat as cache miss → 401.
            return null;
        }
    }

    /**
     * Store an API key payload in Redis as a Hash with a TTL.
     *
     * Uses a pipeline to execute HSET + EXPIRE in a single round-trip,
     * eliminating the race condition where the key exists without a TTL
     * between two separate HSET and EXPIRE calls.
     *
     * @param  string             $keyHash     64-char SHA-256 digest (Redis key suffix).
     * @param  ApiKeyCachePayload $payload     Data to store.
     * @param  int                $ttlSeconds  Time-to-live; must be > 0.
     */
    public function put(string $keyHash, ApiKeyCachePayload $payload, int $ttlSeconds): void
    {
        $redisKey = $this->keyBuilder->apiKeyHash($keyHash);
        $fields   = $payload->toRedisHash();

        // Pipeline guarantees HSET and EXPIRE are sent atomically in one batch.
        Redis::connection($this->config->redisConnection)
            ->pipeline(function ($pipe) use ($redisKey, $fields, $ttlSeconds): void {
                // HSET accepts a flat key-value array in phpredis 5+.
                $pipe->hset($redisKey, ...$this->flattenHash($fields));
                $pipe->expire($redisKey, max(1, $ttlSeconds));
            });
    }

    /**
     * Remove a cached API key entry from Redis immediately.
     *
     * Called by ApiKeyObserver on 'updated' (revoked/expired) and 'deleted'.
     * Also called explicitly by ApiKeyService::invalidateCache() for cases
     * where the caller already has the key_hash in scope.
     *
     * @param  string  $keyHash  64-char SHA-256 digest identifying the cache entry.
     */
    public function forget(string $keyHash): void
    {
        try {
            $redisKey = $this->keyBuilder->apiKeyHash($keyHash);
            Redis::connection($this->config->redisConnection)->del($redisKey);
        } catch (\Throwable) {
            // Swallow: if Redis is unavailable, the key will expire naturally via TTL.
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Converts an associative hash array to a flat alternating key-value list
     * compatible with phpredis HSET variadic syntax:
     *   HSET key field1 value1 field2 value2 ...
     *
     * @param  array<string, string>  $hash
     * @return list<string>
     */
    private function flattenHash(array $hash): array
    {
        $flat = [];
        foreach ($hash as $field => $value) {
            $flat[] = $field;
            $flat[] = $value;
        }
        return $flat;
    }
}

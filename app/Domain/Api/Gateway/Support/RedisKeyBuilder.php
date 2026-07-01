<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Support;

use App\Domain\Api\Gateway\Config\GatewayConfig;

/**
 * Centralised factory for all Redis key strings used by the gateway.
 *
 * All Redis keys in the gateway follow a namespaced format:
 *
 *   api_keys:{sha256_hash}                — API key authentication cache (Hash)
 *   rate_limit:{tenant_id}:{api_key_id}   — Sliding window counter (Sorted Set)
 *   api_usage_logs:buffer                 — Async log write buffer (List)
 *
 * Centralising key construction here ensures:
 *   1. No key string is duplicated or hand-crafted across the codebase.
 *   2. Prefix changes only require a config update, not a code search.
 *   3. Unit tests can verify key format in a single place.
 *
 * All methods are pure functions — no Redis I/O.
 */
final class RedisKeyBuilder
{
    public function __construct(
        private readonly GatewayConfig $config,
    ) {}

    /**
     * Returns the Redis Hash key for a cached API key.
     *
     * Pattern: {api_key_prefix}{sha256_hash}
     * Example: api_keys:a3f1b2c4d5e6...  (prefix from config + 64-char hex)
     *
     * Used by: ApiKeyCacheService (HGETALL, HSET, DEL)
     *
     * @param  string  $keyHash  64-char SHA-256 hex digest of the plain token.
     */
    public function apiKeyHash(string $keyHash): string
    {
        return $this->config->apiKeyPrefix . $keyHash;
    }

    /**
     * Returns the Redis Sorted Set key for a sliding-window rate limiter.
     *
     * Pattern: {rate_limit_prefix}{tenant_id}:{api_key_id}
     * Example: rate_limit:550e8400-...:42
     *
     * The key is scoped to both tenant AND key so different keys owned by the
     * same tenant each have independent rate-limit counters.
     *
     * Used by: SlidingWindowRateLimiter (ZREMRANGEBYSCORE, ZCARD, ZADD, PEXPIRE)
     *
     * @param  string  $tenantId   UUID of the tenant.
     * @param  int     $apiKeyId   Bigint primary key of the api_keys row.
     */
    public function rateLimit(string $tenantId, int $apiKeyId): string
    {
        return $this->config->rateLimitPrefix . $tenantId . ':' . $apiKeyId;
    }

    /**
     * Returns the Redis List key used as the async usage-log write buffer.
     *
     * Pattern: {usage_log_buffer_key}  (fully configured, no dynamic parts)
     * Example: api_usage_logs:buffer
     *
     * Used by: ApiGatewayGuardMiddleware::terminate() (LPUSH)
     *          ProcessApiUsageLogs job (LLEN, RPOP pipeline)
     */
    public function usageLogBuffer(): string
    {
        return $this->config->usageLogBufferKey;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Services;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Data\RateLimitResult;
use App\Domain\Api\Gateway\Support\RedisKeyBuilder;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed sliding window rate limiter using an atomic Lua script.
 *
 * Algorithm: True Sliding Window Log (not Fixed Window, not Token Bucket)
 *
 * Data structure: Redis Sorted Set
 *   Key:    rate_limit:{tenant_id}:{api_key_id}
 *   Member: {timestamp_ms}:{random_hex}  (unique per request)
 *   Score:  Unix timestamp in milliseconds (enables range-based pruning)
 *
 * Why Lua for atomicity?
 *   The ZREMRANGEBYSCORE → ZCARD → ZADD sequence has a TOCTOU (time-of-check
 *   to time-of-use) race condition if executed as separate commands. Under
 *   high concurrency, two requests could both read count = N-1 and both
 *   succeed even though only one should. The Lua script runs atomically inside
 *   Redis's single-threaded event loop, eliminating this race entirely.
 *
 * Fail-open behaviour:
 *   If Redis is unavailable or eval returns an unexpected value, the request
 *   is allowed through (RateLimitResult::failOpen). A Redis outage should not
 *   cause a gateway-wide 429 storm.
 */
final class SlidingWindowRateLimiter
{
    /**
     * Atomic Lua script executed via Redis EVAL.
     *
     * KEYS[1]  — Sorted Set key (rate_limit:{tenant}:{key_id})
     * ARGV[1]  — Current timestamp in milliseconds (from PHP, not Redis clock)
     * ARGV[2]  — Window size in milliseconds
     * ARGV[3]  — Request limit (integer ceiling)
     * ARGV[4]  — Unique member string for this request's ZADD entry
     *
     * Returns a 3-element Lua table: {allowed, remaining, retry_after_seconds}
     *   allowed           : 1 = request passes, 0 = rate limit exceeded
     *   remaining         : requests left in window (0 when denied)
     *   retry_after_seconds : seconds until oldest entry expires (0 when allowed)
     */
    private const LUA_SCRIPT = <<<'LUA'
        local key        = KEYS[1]
        local now_ms     = tonumber(ARGV[1])
        local window_ms  = tonumber(ARGV[2])
        local limit      = tonumber(ARGV[3])
        local member     = ARGV[4]

        -- Step 1: Evict all entries that have slid out of the current window.
        -- This keeps the sorted set bounded to at most `limit` members at steady state.
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now_ms - window_ms)

        -- Step 2: Count requests currently in the window (after eviction).
        local count = tonumber(redis.call('ZCARD', key))

        -- Step 3: Enforce the ceiling.
        if count >= limit then
            -- Determine when the client can retry by finding the oldest entry
            -- still in the window. When it expires, a new slot opens.
            local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
            local retry_after_ms
            if oldest and #oldest >= 2 then
                -- oldest[2] is the score (timestamp_ms) of the oldest entry.
                retry_after_ms = tonumber(oldest[2]) + window_ms - now_ms
            else
                -- Fallback: wait the full window.
                retry_after_ms = window_ms
            end
            -- Return: {denied, remaining=0, retry_after_seconds}
            return {0, 0, math.ceil(retry_after_ms / 1000)}
        end

        -- Step 4: Record this request in the sorted set with timestamp as score.
        -- The unique member prevents score collisions for concurrent requests
        -- arriving in the same millisecond.
        redis.call('ZADD', key, now_ms, member)

        -- Refresh the key's TTL so it self-destructs when the window empties.
        -- PEXPIRE uses milliseconds for precision.
        redis.call('PEXPIRE', key, window_ms)

        -- Return: {allowed, remaining=limit-count-1, retry_after=0}
        return {1, limit - count - 1, 0}
    LUA;

    public function __construct(
        private readonly GatewayConfig $config,
        private readonly RedisKeyBuilder $keyBuilder,
    ) {}

    /**
     * Attempt to consume one request slot from the sliding window.
     *
     * This is the ONLY method called by the middleware on the hot path.
     * It executes a single Redis EVAL command (one round-trip) and returns
     * a typed result object the middleware can act on immediately.
     *
     * @param  string  $tenantId      UUID of the tenant owning the key.
     * @param  int     $apiKeyId      Bigint PK of the api_keys row.
     * @param  int     $limit         Maximum requests allowed in the window.
     * @param  int     $windowSeconds Window size in seconds.
     */
    public function attempt(
        string $tenantId,
        int $apiKeyId,
        int $limit,
        int $windowSeconds,
    ): RateLimitResult {
        try {
            $key       = $this->keyBuilder->rateLimit($tenantId, $apiKeyId);
            $nowMs     = $this->nowMs();
            $windowMs  = $windowSeconds * 1_000;

            // Generate a unique member to handle concurrent requests in the same
            // millisecond. 4 random bytes (8 hex chars) gives 2^32 combinations.
            $member    = $nowMs . ':' . bin2hex(random_bytes(4));

            $connection = Redis::connection($this->config->redisConnection);

            /**
             * Laravel PhpRedisConnection::eval() signature (≠ phpredis native):
             *   eval(string $script, int $numberOfKeys, mixed ...$keysAndArgs)
             *
             * The wrapper internally calls phpredis as eval($script, $keysAndArgs, $numberOfKeys).
             * Passing the array as $numberOfKeys (phpredis style) causes the call to fail silently.
             */
            $result = $connection->eval(
                self::LUA_SCRIPT,
                1,              // $numberOfKeys  — one KEYS element
                $key,           // KEYS[1]
                (string) $nowMs,    // ARGV[1]
                (string) $windowMs, // ARGV[2]
                (string) $limit,    // ARGV[3]
                $member,            // ARGV[4]
            );

            // Guard against unexpected return types (Redis error, wrong phpredis version).
            if (!is_array($result) || count($result) < 3) {
                return RateLimitResult::failOpen($limit);
            }

            return new RateLimitResult(
                allowed:           (bool) ($result[0] ?? 0),
                limit:             $limit,
                remaining:         max(0, (int) ($result[1] ?? 0)),
                retryAfterSeconds: max(0, (int) ($result[2] ?? 0)),
            );
        } catch (\Throwable) {
            // Fail-open: do not return 429 when Redis is unavailable.
            // A degraded rate-limit is better than a complete service outage.
            return RateLimitResult::failOpen($limit);
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the current Unix timestamp in milliseconds.
     *
     * Uses microtime(true) for sub-second precision. This is passed to the
     * Lua script as ARGV[1] so both PHP and Lua operate on the same clock
     * reference rather than relying on Redis's own TIME command.
     */
    private function nowMs(): int
    {
        return (int) (microtime(true) * 1_000);
    }
}

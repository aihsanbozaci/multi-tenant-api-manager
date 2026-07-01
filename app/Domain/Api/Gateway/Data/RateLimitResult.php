<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Data;

/**
 * Immutable result of a sliding-window rate-limit check.
 *
 * Returned by SlidingWindowRateLimiter::attempt() after the atomic Lua
 * script executes. The middleware maps these fields directly to HTTP
 * response headers and the 429 JSON body without any further computation.
 *
 * Header mapping:
 *   $limit            → X-RateLimit-Limit
 *   $remaining        → X-RateLimit-Remaining
 *   $retryAfterSeconds → Retry-After  (only on 429 responses)
 */
final readonly class RateLimitResult
{
    public function __construct(
        /** True when the request is within the rate limit and should be forwarded. */
        public readonly bool $allowed,

        /** The configured ceiling for this key (rate_limit_max from Redis hash). */
        public readonly int $limit,

        /**
         * How many more requests are allowed in the current window.
         * Always 0 when $allowed is false.
         */
        public readonly int $remaining,

        /**
         * Seconds until the client may retry.
         * 0 when $allowed is true; positive integer when $allowed is false.
         * Derived from the oldest entry in the sliding-window sorted set.
         */
        public readonly int $retryAfterSeconds,
    ) {}

    /**
     * Convenience constructor for a "fail-open" result used when Redis is
     * unavailable. Allows the request through with minimal header exposure
     * to avoid a total service outage.
     */
    public static function failOpen(int $limit): self
    {
        return new self(
            allowed:           true,
            limit:             $limit,
            remaining:         0,
            retryAfterSeconds: 0,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Data;

use App\Domain\Api\Gateway\Enums\ApiKeyStatus;

/**
 * Typed value object representing the data stored in the Redis hash
 * at key: api_keys:{sha256_hash_of_token}
 *
 * This is the ONLY struct that crosses the Redis ↔ PHP boundary for API key
 * lookups. Nothing else is read from Redis (or MySQL) on the hot path.
 *
 * Redis hash fields (all stored as strings, cast here to native types):
 *   tenant_id        → string  (UUID)
 *   api_key_id       → int     (bigint primary key)
 *   rate_limit_max   → int     (max requests allowed in the window)
 *   rate_limit_window → int    (window size in seconds)
 *   status           → string  (ApiKeyStatus backed-enum value)
 *
 * Immutability: declared readonly so no field can be mutated after
 * construction. A new instance must be created for any update.
 */
final readonly class ApiKeyCachePayload
{
    public function __construct(
        /** UUID of the tenant that owns this key. */
        public readonly string $tenantId,

        /** Bigint primary key from the api_keys table. */
        public readonly int $apiKeyId,

        /** Maximum number of requests allowed within the rate-limit window. */
        public readonly int $rateLimitMax,

        /** Rate-limit window duration in seconds (e.g. 60 = 1 minute). */
        public readonly int $rateLimitWindow,

        /** Current lifecycle status of the key. */
        public readonly ApiKeyStatus $status,
    ) {}

    // -----------------------------------------------------------------------
    // Factory
    // -----------------------------------------------------------------------

    /**
     * Hydrate from the raw string map returned by Redis HGETALL.
     *
     * All Redis values are plain strings; this method casts them to the
     * correct PHP types and throws if required fields are missing.
     *
     * @param  array<string, string>  $hash  Raw Redis HGETALL result.
     * @throws \InvalidArgumentException When a required field is absent.
     */
    public static function fromRedisHash(array $hash): self
    {
        $required = ['tenant_id', 'api_key_id', 'rate_limit_max', 'rate_limit_window', 'status'];

        foreach ($required as $field) {
            if (!isset($hash[$field])) {
                throw new \InvalidArgumentException(
                    "Redis API key cache hash is missing required field: {$field}"
                );
            }
        }

        return new self(
            tenantId:        $hash['tenant_id'],
            apiKeyId:        (int) $hash['api_key_id'],
            rateLimitMax:    (int) $hash['rate_limit_max'],
            rateLimitWindow: (int) $hash['rate_limit_window'],
            status:          ApiKeyStatus::from($hash['status']),
        );
    }

    // -----------------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------------

    /**
     * Converts this payload to a flat string map suitable for Redis HSET.
     * All values are cast to strings as required by the Redis protocol.
     *
     * @return array<string, string>
     */
    public function toRedisHash(): array
    {
        return [
            'tenant_id'         => $this->tenantId,
            'api_key_id'        => (string) $this->apiKeyId,
            'rate_limit_max'    => (string) $this->rateLimitMax,
            'rate_limit_window' => (string) $this->rateLimitWindow,
            'status'            => $this->status->value,
        ];
    }

    // -----------------------------------------------------------------------
    // Domain logic
    // -----------------------------------------------------------------------

    /**
     * Returns true if the key is active and should be allowed through.
     * This is the single point-of-truth check called by the middleware;
     * no further DB or Redis lookup is needed.
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

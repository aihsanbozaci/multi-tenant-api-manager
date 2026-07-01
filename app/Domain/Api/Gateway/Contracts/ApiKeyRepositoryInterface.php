<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Contracts;

use App\Domain\Api\Gateway\Data\ApiKeyCachePayload;
use App\Domain\Api\Gateway\Enums\ApiKeyStatus;
use Carbon\CarbonImmutable;

/**
 * Repository contract for ApiKey persistence operations.
 *
 * All methods that touch the database are intentionally kept off the hot path.
 * The middleware only reads from Redis (via ApiKeyCacheService); this interface
 * is used by ApiKeyService for write operations and cache warm-up.
 *
 * Package-conversion note:
 *   - This interface stays in the package namespace.
 *   - The host app binds a concrete implementation (e.g. EloquentApiKeyRepository)
 *     inside its service provider, or accepts the package-default binding.
 */
interface ApiKeyRepositoryInterface
{
    /**
     * Persist a new API key record to the database.
     *
     * The implementation is responsible for:
     *   - Storing key_id (first 8 chars of the plain token, plain text, indexed).
     *   - Storing key_hash (SHA-256 hex digest, unique, 64 chars).
     *   - NOT storing the plain token — it is discarded after this call.
     *
     * @param  string              $tenantId       UUID of the owning tenant.
     * @param  string              $name           Human-readable label for the key.
     * @param  string              $keyId          First 8 characters of the plain token.
     * @param  string              $keyHash        SHA-256 hex digest of the plain token.
     * @param  int                 $rateLimitMax   Max requests in the window.
     * @param  int                 $rateLimitWindow Window size in seconds.
     * @param  CarbonImmutable|null $expiresAt     Optional expiry; null = never expires.
     * @return int                                 Auto-increment primary key of the new row.
     */
    public function create(
        string $tenantId,
        string $name,
        string $keyId,
        string $keyHash,
        int $rateLimitMax,
        int $rateLimitWindow,
        ?CarbonImmutable $expiresAt,
    ): int;

    /**
     * Retrieve the data needed to populate the Redis cache for a given key ID.
     * Returns null when the key does not exist.
     *
     * @param  int  $apiKeyId  Primary key of the api_keys row.
     */
    public function findCachePayloadById(int $apiKeyId): ?ApiKeyCachePayload;

    /**
     * Retrieve the data needed to populate the Redis cache by the SHA-256 hash.
     * Returns null when no matching key is found (e.g. unknown token).
     *
     * @param  string  $keyHash  64-char SHA-256 hex digest.
     */
    public function findCachePayloadByHash(string $keyHash): ?ApiKeyCachePayload;

    /**
     * Update the status of an existing key.
     * The Observer will react to the Eloquent 'updated' event and sync Redis.
     *
     * @param  int            $apiKeyId  Primary key of the api_keys row.
     * @param  ApiKeyStatus   $status    New status value to persist.
     */
    public function updateStatus(int $apiKeyId, ApiKeyStatus $status): void;

    /**
     * Hard-delete a key record from the database.
     * Prefer updateStatus(Revoked) for soft invalidation; this is for
     * administrative cleanup only.
     *
     * @param  int  $apiKeyId  Primary key of the api_keys row.
     */
    public function delete(int $apiKeyId): void;
}

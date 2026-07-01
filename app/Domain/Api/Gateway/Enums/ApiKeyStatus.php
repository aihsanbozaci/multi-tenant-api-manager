<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Enums;

/**
 * Represents the lifecycle state of an ApiKey record.
 *
 * Stored as a VARCHAR(16) column in the api_keys table.
 * Cast automatically by the ApiKey Eloquent model.
 * Mirrored into the Redis hash field 'status' for zero-DB hot-path checks.
 *
 * Transitions:
 *   active → revoked  (manual revocation or expiry sweep)
 *   No resurrection: a revoked key must be replaced by a new key.
 */
enum ApiKeyStatus: string
{
    /** Key is valid and can authenticate requests. */
    case Active = 'active';

    /** Key has been permanently invalidated. Redis cache entry is DEL'd by the Observer. */
    case Revoked = 'revoked';

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true when the key status permits request forwarding.
     * Called in ApiKeyCacheService after an HGETALL lookup to avoid any
     * additional round-trip to MySQL on the hot path.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Human-readable label for admin UIs, audit logs, and error messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active  => 'Active',
            self::Revoked => 'Revoked',
        };
    }

    /**
     * Returns all status values that must cause a 401 response.
     * Useful for Redis cache comparisons using plain string values.
     *
     * @return list<string>
     */
    public static function unauthorizedValues(): array
    {
        return [
            self::Revoked->value,
        ];
    }
}

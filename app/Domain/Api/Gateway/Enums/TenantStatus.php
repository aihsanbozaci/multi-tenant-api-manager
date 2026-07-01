<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Enums;

/**
 * Represents the lifecycle state of a Tenant record.
 *
 * Stored as a VARCHAR(32) column in the tenants table.
 * Cast automatically by the Tenant Eloquent model.
 *
 * Transitions:
 *   active  → suspended  (admin action, e.g. payment failure)
 *   active  → inactive   (voluntary deactivation)
 *   suspended → active   (re-activation after payment)
 *   any     → inactive   (final soft-disable, no hard delete)
 */
enum TenantStatus: string
{
    /** Tenant is operational and allowed to use the gateway. */
    case Active = 'active';

    /** Tenant is temporarily blocked (e.g. unpaid invoice). */
    case Suspended = 'suspended';

    /** Tenant is disabled; keys will not authenticate. */
    case Inactive = 'inactive';

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true when the tenant status permits API key authentication.
     * Only Active tenants are allowed on the hot path.
     */
    public function isAllowed(): bool
    {
        return $this === self::Active;
    }

    /**
     * Human-readable label suitable for admin UIs and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Active',
            self::Suspended => 'Suspended',
            self::Inactive  => 'Inactive',
        };
    }

    /**
     * Returns all values that represent a "blocked" tenant.
     * Useful for query scopes: WHERE status IN (...).
     *
     * @return list<string>
     */
    public static function blockedValues(): array
    {
        return [
            self::Suspended->value,
            self::Inactive->value,
        ];
    }
}

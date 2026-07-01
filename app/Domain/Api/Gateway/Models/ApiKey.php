<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Models;

use App\Domain\Api\Gateway\Enums\ApiKeyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model representing an API key record.
 *
 * Key design decisions:
 *  - $timestamps = false: the table has only created_at (set by DB default
 *    useCurrent()), no updated_at column. Letting Eloquent manage timestamps
 *    would cause SQL errors when trying to write a non-existent updated_at.
 *    created_at is still readable via the cast definition below.
 *  - key_hash is the SHA-256 hex digest of the plain token. The plain token
 *    is NEVER stored; it is returned once by ApiKeyService::create() and
 *    discarded immediately after.
 *  - key_id (first 8 chars of the plain token) is stored in plain text for
 *    admin UI display only — it is not sufficient to authenticate on its own.
 *  - status is cast to ApiKeyStatus backed enum; the string value mirrors
 *    what is stored in the Redis hash field, so no mapping layer is needed.
 *  - expires_at is cast to CarbonImmutable for immutable datetime comparisons
 *    without accidental mutation.
 *  - getTable() reads from config → loose coupling / package-ready.
 */
class ApiKey extends Model
{
    /**
     * Disable Laravel's automatic timestamp management.
     * created_at is handled by the DB default (useCurrent()); there is no
     * updated_at column in the migration.
     */
    public $timestamps = false;

    /**
     * Columns that are mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'key_id',
        'key_hash',
        'rate_limit_max',
        'rate_limit_window',
        'status',
        'expires_at',
    ];

    /**
     * Attribute → PHP type casts.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status'           => ApiKeyStatus::class,
        'expires_at'       => 'immutable_datetime',
        'created_at'       => 'immutable_datetime',
        'rate_limit_max'   => 'integer',
        'rate_limit_window' => 'integer',
    ];

    // -----------------------------------------------------------------------
    // Table name — loose coupling via config
    // -----------------------------------------------------------------------

    /**
     * Returns the database table name as configured in api-gateway.php.
     */
    public function getTable(): string
    {
        return config('api-gateway.tables.api_keys', 'api_keys');
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /**
     * The tenant that owns this API key.
     *
     * @return BelongsTo<Tenant, ApiKey>
     */
    public function tenant(): BelongsTo
    {
        /** @var class-string<Tenant> $tenantClass */
        $tenantClass = config('api-gateway.models.tenant', Tenant::class);

        return $this->belongsTo($tenantClass, 'tenant_id', 'id');
    }

    // -----------------------------------------------------------------------
    // Domain helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true when this key is both in 'active' status AND not yet expired.
     *
     * This mirrors the check performed by ApiKeyCachePayload::isActive() for
     * cases where a full model instance is available (e.g. admin UIs, tests).
     * The hot-path middleware uses the Redis-cached payload instead.
     */
    public function isActive(): bool
    {
        if ($this->status !== ApiKeyStatus::Active) {
            return false;
        }

        // A null expires_at means the key never expires.
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at instanceof CarbonImmutable
            && $this->expires_at->isFuture();
    }

    /**
     * Returns true when the key has a non-null expires_at that is in the past.
     */
    public function isExpired(): bool
    {
        return $this->expires_at instanceof CarbonImmutable
            && $this->expires_at->isPast();
    }
}

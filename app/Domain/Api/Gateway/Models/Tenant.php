<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Models;

use App\Domain\Api\Gateway\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model representing a tenant in the multi-tenant gateway.
 *
 * Key design decisions:
 *  - HasUuids trait: auto-generates UUID primary keys; sets $keyType = 'string'
 *    and $incrementing = false internally — UUID PKs are safe to expose externally.
 *  - getTable() reads from config so the host app can rename the table without
 *    modifying this class (loose coupling / package-ready pattern).
 *  - status cast to TenantStatus backed enum; never stored/read as raw strings.
 *  - No $guarded = [] shortcut; explicit $fillable enforces assignment safety.
 */
class Tenant extends Model
{
    use HasUuids;

    /**
     * Columns that are mass-assignable.
     * The primary key (id) and timestamps are intentionally excluded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
    ];

    /**
     * Attribute → PHP type casts.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status'     => TenantStatus::class,
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    // -----------------------------------------------------------------------
    // Table name — loose coupling via config
    // -----------------------------------------------------------------------

    /**
     * Returns the database table name as configured in api-gateway.php.
     * Overriding getTable() rather than setting $table as a property allows
     * the config to be read lazily (after the service provider has merged it).
     */
    public function getTable(): string
    {
        return config('api-gateway.tables.tenants', 'tenants');
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /**
     * All API keys that belong to this tenant.
     *
     * @return HasMany<ApiKey>
     */
    public function apiKeys(): HasMany
    {
        /** @var class-string<ApiKey> $apiKeyClass */
        $apiKeyClass = config('api-gateway.models.api_key', ApiKey::class);

        return $this->hasMany($apiKeyClass, 'tenant_id', 'id');
    }

    // -----------------------------------------------------------------------
    // Domain helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true if this tenant is permitted to authenticate API requests.
     * Delegates to the enum so business logic is centralised in one place.
     */
    public function isAllowed(): bool
    {
        return $this->status instanceof TenantStatus && $this->status->isAllowed();
    }
}

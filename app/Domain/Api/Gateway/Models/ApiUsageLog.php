<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model representing a single API usage log entry.
 *
 * Key design decisions:
 *  - BIGINT AUTO_INCREMENT primary key: this is a high-throughput append-only
 *    table. Sequential inserts avoid B-tree fragmentation. The id is an internal
 *    surrogate key never exposed in API responses or audit exports.
 *  - $timestamps = false: this table has no created_at / updated_at columns.
 *    The authoritative timestamp is requested_at, set by the middleware at
 *    request time (not by the DB server), to preserve the original request
 *    timing even after asynchronous bulk writes.
 *  - All columns are in $fillable because this model is exclusively written
 *    via bulk-insert arrays in EloquentApiUsageLogRepository::bulkInsert().
 *    No user-facing form ever populates this model directly.
 *  - getTable() reads from config → loose coupling / package-ready.
 *  - No Eloquent relationships defined: this is a pure append-only log table.
 *    Cross-model joins are performed at the query-builder level in repositories.
 */
class ApiUsageLog extends Model
{

    /**
     * Disable automatic timestamp management (no created_at / updated_at columns).
     */
    public $timestamps = false;

    /**
     * All log columns are mass-assignable. The primary key (id) is excluded
     * because HasUuids generates it automatically before insert.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'endpoint',
        'method',
        'status_code',
        'response_time_ms',
        'payload_size_bytes',
        'requested_at',
    ];

    /**
     * Attribute → PHP type casts.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'api_key_id'         => 'integer',
        'status_code'        => 'integer',
        'response_time_ms'   => 'integer',
        'payload_size_bytes' => 'integer',
        'requested_at'       => 'immutable_datetime',
    ];

    // -----------------------------------------------------------------------
    // Table name — loose coupling via config
    // -----------------------------------------------------------------------

    /**
     * Returns the database table name as configured in api-gateway.php.
     */
    public function getTable(): string
    {
        return config('api-gateway.tables.api_usage_logs', 'api_usage_logs');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Contracts;

use App\Domain\Api\Gateway\Data\ApiUsageLogEntry;

/**
 * Repository contract for ApiUsageLog persistence operations.
 *
 * Log entries are NEVER written synchronously on the request path.
 * The flow is:
 *   1. ApiGatewayGuardMiddleware::terminate() → push JSON to Redis list buffer.
 *   2. ProcessApiUsageLogs job → pops a batch from the buffer → calls bulkInsert().
 *
 * This contract separates the persistence concern from the job/middleware so
 * that the storage backend can be swapped (e.g. ClickHouse, TimescaleDB) by
 * binding a different implementation in the service provider.
 *
 * Package-conversion note:
 *   - Interface stays in the package namespace.
 *   - Host app (or package default) provides the concrete implementation.
 */
interface ApiUsageLogRepositoryInterface
{
    /**
     * Persist a single log entry.
     *
     * Prefer bulkInsert() for worker/job contexts. This method exists for
     * low-volume scenarios such as integration tests or one-off scripts.
     *
     * @param  ApiUsageLogEntry  $entry  The log event to persist.
     */
    public function insert(ApiUsageLogEntry $entry): void;

    /**
     * Persist multiple log entries in a single database round-trip.
     *
     * Implementations MUST chunk large arrays to avoid hitting MySQL's
     * max_allowed_packet limit. The recommended chunk size is 100 rows.
     *
     * @param  list<ApiUsageLogEntry>  $entries  Batch of log events to persist.
     *                                            Empty arrays are a no-op.
     */
    public function bulkInsert(array $entries): void;

    /**
     * Count total log entries for a given tenant within a time range.
     *
     * Used by analytics/reporting endpoints — NOT on the request hot path.
     *
     * @param  string              $tenantId   UUID of the tenant.
     * @param  \DateTimeInterface  $from       Range start (inclusive).
     * @param  \DateTimeInterface  $to         Range end (inclusive).
     * @return int                             Number of matching log rows.
     */
    public function countForTenant(
        string $tenantId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): int;

    /**
     * Purge log entries older than the given date.
     *
     * Intended for scheduled maintenance commands that enforce a retention
     * policy (e.g. delete logs older than 90 days).
     *
     * @param  \DateTimeInterface  $before  Delete rows where requested_at < $before.
     * @return int                          Number of rows deleted.
     */
    public function purgeOlderThan(\DateTimeInterface $before): int;
}

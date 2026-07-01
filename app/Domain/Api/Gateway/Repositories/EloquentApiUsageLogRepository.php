<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Repositories;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Contracts\ApiUsageLogRepositoryInterface;
use App\Domain\Api\Gateway\Data\ApiUsageLogEntry;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed (query-builder level) implementation of ApiUsageLogRepositoryInterface.
 *
 * Uses DB::table() directly rather than the ApiUsageLog Eloquent model for
 * bulk-insert operations. This avoids the overhead of instantiating individual
 * model objects (no boot(), no casting, no event firing) which would be wasteful
 * for high-throughput append-only writes.
 *
 * The single insert() method uses DB::table()->insert() as well for consistency;
 * the Eloquent model is available for reads/queries outside the hot path.
 *
 * IMPORTANT — Hot-path rule enforcement:
 *   This repository is ONLY called by the ProcessApiUsageLogs job worker, which
 *   runs AFTER the HTTP response has been sent to the client. It is never
 *   invoked during the request auth / rate-limit flow.
 */
class EloquentApiUsageLogRepository implements ApiUsageLogRepositoryInterface
{
    /**
     * Number of rows per chunk when iterating large bulkInsert() batches.
     * Prevents hitting MySQL's max_allowed_packet limit for very large arrays.
     */
    private const INSERT_CHUNK_SIZE = 100;

    public function __construct(
        private readonly GatewayConfig $config,
    ) {}

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Returns a query builder scoped to the configured usage-log table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function table(): \Illuminate\Database\Query\Builder
    {
        return DB::table($this->config->apiUsageLogsTable);
    }

    /**
     * Converts an array of ApiUsageLogEntry DTOs to plain arrays for DB insert.
     *
     * @param  list<ApiUsageLogEntry>  $entries
     * @return list<array<string, int|string>>
     */
    private function entriesToRows(array $entries): array
    {
        return array_map(
            static fn(ApiUsageLogEntry $entry): array => $entry->toArray(),
            $entries
        );
    }

    // -----------------------------------------------------------------------
    // ApiUsageLogRepositoryInterface implementation
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function insert(ApiUsageLogEntry $entry): void
    {
        $this->table()->insert($entry->toArray());
    }

    /**
     * {@inheritdoc}
     *
     * Rows are chunked into INSERT_CHUNK_SIZE batches so a single bulk-insert
     * call with thousands of entries won't exceed MySQL's max_allowed_packet.
     */
    public function bulkInsert(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $rows = $this->entriesToRows($entries);

        // array_chunk preserves numeric keys; array_values normalises the chunk.
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $this->table()->insert($chunk);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function countForTenant(
        string $tenantId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): int {
        return (int) $this->table()
            ->where('tenant_id', $tenantId)
            ->whereBetween('requested_at', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ])
            ->count();
    }

    /**
     * {@inheritdoc}
     */
    public function purgeOlderThan(\DateTimeInterface $before): int
    {
        return (int) $this->table()
            ->where('requested_at', '<', $before->format('Y-m-d H:i:s'))
            ->delete();
    }
}

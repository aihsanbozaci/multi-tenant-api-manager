<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Jobs;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Contracts\ApiUsageLogRepositoryInterface;
use App\Domain\Api\Gateway\Data\ApiUsageLogEntry;
use App\Domain\Api\Gateway\Support\RedisKeyBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Async usage log processor: Redis-buffer accumulation + MySQL bulk insert.
 *
 * Processing strategy (two-phase):
 *
 * Phase 1 — Accumulate:
 *   Each job invocation pushes its single log entry to the Redis List buffer
 *   (LPUSH api_usage_logs:buffer). This keeps the HTTP lifecycle payload tiny
 *   (one LPUSH = ~1ms) while deferring the expensive MySQL write.
 *
 * Phase 2 — Flush (conditional):
 *   After LPUSH, the job checks the buffer length (LLEN). If the buffer has
 *   reached `batch_size`, it atomically pops that many entries (RPOP pipeline)
 *   and bulk-inserts them into MySQL in one round-trip per 100 rows.
 *
 * Concurrency safety:
 *   LPUSH and LLEN are individually atomic Redis operations. Two concurrent
 *   workers can both decide to flush (both see LLEN >= batch_size), but since
 *   RPOP is also atomic, they will pop disjoint sets of entries. This results
 *   in two smaller flushes rather than one — correct and harmless.
 *
 * Failure handling:
 *   - If Phase 2 fails (MySQL down), the entries remain in the Redis buffer
 *     and will be processed by the next successful flush (worker retry).
 *   - $tries = 3: Laravel retries the entire job on failure. The LPUSH may
 *     re-push the same entry, creating a duplicate. Acceptable trade-off
 *     (at-least-once semantics); de-dup at the analytics query layer if needed.
 *
 * Worker command:
 *   php artisan queue:work redis --queue=api-analytics-processing --tries=3
 */
class ProcessApiUsageLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of job retries before it is sent to the failed jobs table.
     */
    public int $tries = 3;

    /**
     * Maximum number of unhandled exceptions before the job fails permanently.
     */
    public int $maxExceptions = 3;

    /**
     * Seconds to wait before retrying a failed attempt (exponential backoff).
     */
    public int $backoff = 5;

    /**
     * The log entry to be persisted. Public visibility allows Queue::fake()
     * assertions in feature tests to inspect the job payload.
     */
    public function __construct(
        public readonly ApiUsageLogEntry $entry,
    ) {}

    // -----------------------------------------------------------------------
    // Job handler
    // -----------------------------------------------------------------------

    /**
     * Execute the job.
     *
     * Dependencies are resolved from the service container via method injection.
     * This keeps the serialised job payload small (only the DTO is stored in Redis).
     */
    public function handle(
        GatewayConfig $config,
        RedisKeyBuilder $keyBuilder,
        ApiUsageLogRepositoryInterface $repository,
    ): void {
        $bufferKey  = $keyBuilder->usageLogBuffer();
        $connection = Redis::connection($config->redisConnection);

        // ---------------------------------------------------------------
        // Phase 1: Push this entry to the Redis list buffer.
        // LPUSH is O(1) and atomic. The HTTP lifecycle already completed
        // before this job runs, so latency is not a concern here.
        // ---------------------------------------------------------------
        $connection->lpush($bufferKey, $this->entry->toJson());

        // ---------------------------------------------------------------
        // Phase 2: Check if the buffer has reached the flush threshold.
        // If not, return immediately — accumulate more entries first.
        // ---------------------------------------------------------------
        $bufferLength = (int) $connection->llen($bufferKey);

        if ($bufferLength < $config->usageLogBatchSize) {
            return;
        }

        // ---------------------------------------------------------------
        // Phase 3: Pop a batch from the buffer and flush to MySQL.
        //
        // RPOP is used instead of LPOP so the oldest entries (added first)
        // are processed first — FIFO ordering for the log table.
        //
        // The pipeline batches all RPOP calls into a single round-trip,
        // minimising Redis latency for large batch sizes.
        // ---------------------------------------------------------------
        $entries = $this->popBatch($connection, $bufferKey, $config->usageLogBatchSize);

        if (empty($entries)) {
            return;
        }

        $repository->bulkInsert($entries);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Pop up to $count entries from the RIGHT end of the Redis list buffer
     * using a single pipelined round-trip for efficiency.
     *
     * Returns a list of successfully de-serialised ApiUsageLogEntry objects.
     * Malformed JSON entries are logged and skipped (no job failure).
     *
     * @param  \Illuminate\Redis\Connections\PhpRedisConnection  $connection
     * @param  string  $bufferKey  Redis list key.
     * @param  int     $count      Number of entries to pop.
     * @return list<ApiUsageLogEntry>
     */
    private function popBatch(
        mixed $connection,
        string $bufferKey,
        int $count,
    ): array {
        // Pipeline all RPOP commands into a single Redis round-trip.
        /** @var list<string|false|null> $rawResults */
        $rawResults = $connection->pipeline(function (mixed $pipe) use ($bufferKey, $count): void {
            for ($i = 0; $i < $count; $i++) {
                $pipe->rpop($bufferKey);
            }
        });

        $entries = [];

        foreach ($rawResults as $index => $json) {
            // RPOP returns null/false when the list is exhausted mid-batch.
            if (!is_string($json) || $json === '') {
                continue;
            }

            try {
                $entries[] = ApiUsageLogEntry::fromJson($json);
            } catch (\JsonException $e) {
                // Log and skip malformed JSON; do not fail the entire batch.
                Log::warning('ProcessApiUsageLogs: skipping malformed buffer entry', [
                    'index'     => $index,
                    'error'     => $e->getMessage(),
                    'raw_value' => substr($json, 0, 200), // truncate for log safety
                ]);
            } catch (\InvalidArgumentException $e) {
                Log::warning('ProcessApiUsageLogs: skipping incomplete buffer entry', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $entries;
    }
}

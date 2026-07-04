<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Api\Gateway;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Contracts\ApiUsageLogRepositoryInterface;
use App\Domain\Api\Gateway\Data\ApiUsageLogEntry;
use App\Domain\Api\Gateway\Jobs\ProcessApiUsageLogs;
use App\Domain\Api\Gateway\Support\RedisKeyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integration tests for ProcessApiUsageLogs job.
 *
 * Purpose: Verify the full Redis-buffer → MySQL bulk-insert pipeline works
 * end-to-end with a real database. These tests intentionally do NOT use
 * Queue::fake() so the job's handle() method runs against actual Redis and
 * MySQL — catching bugs that Queue::fake() would silently swallow.
 *
 * Why these tests were added:
 *   ApiUsageLogEntry::toArray() was missing the 'id' field required by the
 *   api_usage_logs UUID primary key. Every bulkInsert() call silently failed
 *   with "Field 'id' doesn't have a default value" because DB::table()->insert()
 *   does not trigger Eloquent HasUuids. Queue::fake() in the middleware tests
 *   prevented job execution, so the bug was invisible to CI.
 *
 * Configuration notes (phpunit.xml):
 *   - QUEUE_CONNECTION=sync  → jobs run synchronously inside the test process.
 *   - REDIS_DB=1             → isolated from production data on DB 0.
 *   - DB_DATABASE=multi_tenant_api_test → dedicated test database.
 */
class ProcessApiUsageLogsTest extends TestCase
{
    use RefreshDatabase;

    private GatewayConfig $config;
    private RedisKeyBuilder $keyBuilder;
    private ApiUsageLogRepositoryInterface $repository;

    /** Shared tenant UUID used across test scenarios. */
    private string $tenantId;

    /** Shared api_key PK used across test scenarios. */
    private int $apiKeyId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config     = app(GatewayConfig::class);
        $this->keyBuilder = app(RedisKeyBuilder::class);
        $this->repository = app(ApiUsageLogRepositoryInterface::class);

        $this->tenantId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        // Clean up the Redis buffer key so tests do not bleed into each other.
        Redis::connection($this->config->redisConnection)
            ->del($this->keyBuilder->usageLogBuffer());

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Regression: id must NOT be in toArray() — MySQL auto-generates it
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * toArray() must NOT include an 'id' key. The api_usage_logs table uses
     * BIGINT AUTO_INCREMENT; MySQL assigns the id automatically on insert.
     * If toArray() included 'id', passing null or a wrong type would break
     * the insert. Omitting it entirely is the correct contract.
     */
    public function test_to_array_does_not_include_id_field(): void
    {
        $entry = $this->makeEntry();

        $this->assertArrayNotHasKey(
            'id',
            $entry->toArray(),
            'toArray() must not include id — MySQL AUTO_INCREMENT handles it.'
        );
    }

    /**
     * @test
     *
     * After a single insert, MySQL must have assigned a positive integer id.
     * This confirms the BIGINT AUTO_INCREMENT column is wired up correctly
     * in the migration and the insert payload does not accidentally override it.
     */
    public function test_mysql_auto_assigns_integer_id_on_insert(): void
    {
        $this->repository->bulkInsert([$this->makeEntry()]);

        $row = DB::table($this->config->apiUsageLogsTable)->first();

        $this->assertNotNull($row);
        $this->assertIsNumeric($row->id);
        $this->assertGreaterThan(0, (int) $row->id);
    }

    // -----------------------------------------------------------------------
    // Repository: bulkInsert writes to MySQL
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * Verify that bulkInsert() actually persists rows to the api_usage_logs
     * table. This catches any column-mismatch or constraint violation that
     * would silently swallow the insert inside a job worker.
     */
    public function test_bulk_insert_writes_rows_to_mysql(): void
    {
        $entries = [
            $this->makeEntry(endpoint: '/api/health',  statusCode: 200),
            $this->makeEntry(endpoint: '/api/resource', statusCode: 404),
            $this->makeEntry(endpoint: '/api/heavy',    statusCode: 200, responseTimeMs: 120),
        ];

        $this->repository->bulkInsert($entries);

        $count = DB::table($this->config->apiUsageLogsTable)->count();

        $this->assertSame(3, $count, 'bulkInsert() must write all entries to the database.');
    }

    /**
     * @test
     *
     * Verify that every column value survives the round-trip through
     * toArray() → DB::table()->insert() → MySQL.
     */
    public function test_bulk_insert_persists_correct_column_values(): void
    {
        $requestedAt = CarbonImmutable::parse('2026-07-01 12:00:00');

        $entry = new ApiUsageLogEntry(
            tenantId:         $this->tenantId,
            apiKeyId:         $this->apiKeyId,
            endpoint:         '/api/orders',
            method:           'POST',
            statusCode:       201,
            responseTimeMs:   42,
            payloadSizeBytes: 512,
            requestedAt:      $requestedAt,
        );

        $this->repository->bulkInsert([$entry]);

        $row = DB::table($this->config->apiUsageLogsTable)->first();

        $this->assertNotNull($row, 'A row must exist after bulkInsert().');
        $this->assertGreaterThan(0, (int) $row->id);
        $this->assertSame($this->tenantId,  $row->tenant_id);
        $this->assertSame($this->apiKeyId,  (int) $row->api_key_id);
        $this->assertSame('/api/orders',    $row->endpoint);
        $this->assertSame('POST',           $row->method);
        $this->assertSame(201,              (int) $row->status_code);
        $this->assertSame(42,               (int) $row->response_time_ms);
        $this->assertSame(512,              (int) $row->payload_size_bytes);
        $this->assertSame('2026-07-01 12:00:00', $row->requested_at);
    }

    // -----------------------------------------------------------------------
    // Job: full Redis-buffer → MySQL pipeline
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * When the Redis buffer has fewer entries than batch_size, the job must
     * push the entry to the buffer and return WITHOUT writing to MySQL.
     * MySQL should remain empty after the job runs.
     */
    public function test_job_accumulates_entries_below_batch_size(): void
    {
        // Use a batch_size of 5 for this test by temporarily binding a
        // custom GatewayConfig with a lower threshold.
        $batchSize = 5;
        $this->overrideBatchSize($batchSize);

        // Dispatch fewer jobs than the flush threshold.
        for ($i = 0; $i < $batchSize - 1; $i++) {
            ProcessApiUsageLogs::dispatchSync($this->makeEntry());
        }

        $bufferLen = (int) Redis::connection($this->config->redisConnection)
            ->llen($this->keyBuilder->usageLogBuffer());

        $dbCount = DB::table($this->config->apiUsageLogsTable)->count();

        $this->assertSame($batchSize - 1, $bufferLen, 'Buffer must hold all entries below the flush threshold.');
        $this->assertSame(0, $dbCount, 'MySQL must not be written until the flush threshold is reached.');
    }

    /**
     * @test
     *
     * When the Redis buffer reaches batch_size, the job must drain the buffer
     * and bulk-insert all entries into MySQL in a single operation.
     */
    public function test_job_flushes_to_mysql_when_batch_size_reached(): void
    {
        $batchSize = 5;
        $this->overrideBatchSize($batchSize);

        // Dispatch exactly batch_size jobs; the last one triggers the flush.
        for ($i = 0; $i < $batchSize; $i++) {
            ProcessApiUsageLogs::dispatchSync($this->makeEntry());
        }

        $bufferLen = (int) Redis::connection($this->config->redisConnection)
            ->llen($this->keyBuilder->usageLogBuffer());

        $dbCount = DB::table($this->config->apiUsageLogsTable)->count();

        $this->assertSame(0, $bufferLen, 'Buffer must be empty after a successful flush.');
        $this->assertSame($batchSize, $dbCount, "MySQL must contain exactly {$batchSize} rows after the flush.");
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build a minimal ApiUsageLogEntry with sensible defaults for testing.
     * Named parameters allow individual tests to override specific fields.
     */
    private function makeEntry(
        string $endpoint      = '/api/health',
        int    $statusCode    = 200,
        int    $responseTimeMs = 5,
    ): ApiUsageLogEntry {
        return new ApiUsageLogEntry(
            tenantId:         $this->tenantId,
            apiKeyId:         $this->apiKeyId,
            endpoint:         $endpoint,
            method:           'GET',
            statusCode:       $statusCode,
            responseTimeMs:   $responseTimeMs,
            payloadSizeBytes: 55,
            requestedAt:      CarbonImmutable::now(),
        );
    }

    /**
     * Rebind GatewayConfig in the service container with a custom batch_size
     * so individual tests can control the flush threshold without touching
     * the real configuration files.
     */
    private function overrideBatchSize(int $batchSize): void
    {
        $raw = config('api-gateway');
        $raw['usage_logs']['batch_size'] = $batchSize;

        $this->app->instance(GatewayConfig::class, GatewayConfig::fromArray($raw));

        // Re-resolve dependent singletons so they pick up the new config.
        $this->app->forgetInstance(\App\Domain\Api\Gateway\Support\RedisKeyBuilder::class);
        $this->keyBuilder = app(RedisKeyBuilder::class);
    }
}

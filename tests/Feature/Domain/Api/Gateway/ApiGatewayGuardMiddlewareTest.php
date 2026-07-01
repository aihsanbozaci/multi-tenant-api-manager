<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Api\Gateway;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Data\ApiKeyCachePayload;
use App\Domain\Api\Gateway\Enums\ApiKeyStatus;
use App\Domain\Api\Gateway\Enums\TenantStatus;
use App\Domain\Api\Gateway\Jobs\ProcessApiUsageLogs;
use App\Domain\Api\Gateway\Models\ApiKey;
use App\Domain\Api\Gateway\Services\ApiKeyCacheService;
use App\Domain\Api\Gateway\Support\TokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for ApiGatewayGuardMiddleware.
 *
 * Isolation strategy:
 *   - DB  : RefreshDatabase wraps each test in a transaction → MySQL rollback.
 *   - Redis: Each test manually controls the cache via ApiKeyCacheService.
 *             setUp() uses ApiKey::withoutObservers() so the Observer never
 *             fires during DB fixture creation — no surprise Redis I/O.
 *             tearDown() explicitly cleans up all Redis keys touched by the test.
 *   - Queue: Queue::fake() intercepts dispatches for assertion without workers.
 *
 * Requirements (phpunit.xml):
 *   DB_CONNECTION=mysql, DB_DATABASE=multi_tenant_api_test (test DB)
 *   REDIS_HOST=laravel_redis (Docker service name), REDIS_DB=1 (isolated)
 *
 * Pre-requisite — create the test database once:
 *   docker exec laravel_mysql mysql -uroot -prootsecret \
 *       -e "CREATE DATABASE IF NOT EXISTS multi_tenant_api_test;"
 */
class ApiGatewayGuardMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Shared fixture state
    // -----------------------------------------------------------------------

    private GatewayConfig $config;
    private ApiKeyCacheService $cacheService;

    /** UUID of the tenant created for each test run. */
    private string $tenantId;

    /** Plain-text token — sent in the X-API-KEY header during requests. */
    private string $plainToken;

    /** SHA-256 hash of $plainToken — used as Redis key suffix and tearDown cleanup. */
    private string $keyHash;

    /** Primary key of the api_keys row. */
    private int $apiKeyId;

    /** Default rate-limit configuration for the primary test key. */
    private const int DEFAULT_RATE_LIMIT_MAX    = 100;
    private const int DEFAULT_RATE_LIMIT_WINDOW = 60;

    // -----------------------------------------------------------------------
    // Test lifecycle
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->config       = app(GatewayConfig::class);
        $this->cacheService = app(ApiKeyCacheService::class);

        // ------------------------------------------------------------------
        // 1. Create a Tenant row directly (no factory needed).
        // ------------------------------------------------------------------
        $this->tenantId = (string) Str::uuid();

        DB::table($this->config->tenantsTable)->insert([
            'id'         => $this->tenantId,
            'name'       => 'Test Tenant',
            'status'     => TenantStatus::Active->value,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        // ------------------------------------------------------------------
        // 2. Generate a token pair without triggering the Observer.
        //    saveQuietly() suppresses all Eloquent model events (including
        //    the 'created' event that would trigger ApiKeyObserver) so no
        //    Redis I/O occurs during DB fixture creation.
        // ------------------------------------------------------------------
        $generator = app(TokenGenerator::class);
        $generated = $generator->generate();

        $this->plainToken = $generated->plainToken;
        $this->keyHash    = $generated->keyHash;

        $apiKeyModel = new ApiKey();
        $apiKeyModel->forceFill([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Test Key',
            'key_id'            => $generated->keyId,
            'key_hash'          => $generated->keyHash,
            'rate_limit_max'    => self::DEFAULT_RATE_LIMIT_MAX,
            'rate_limit_window' => self::DEFAULT_RATE_LIMIT_WINDOW,
            'status'            => ApiKeyStatus::Active->value,
            'expires_at'        => null,
        ]);
        // saveQuietly() == withoutEvents(fn() => save()) — suppresses Observer.
        $apiKeyModel->saveQuietly();
        $this->apiKeyId = (int) $apiKeyModel->id;

        // ------------------------------------------------------------------
        // 3. Manually warm up the Redis cache for the test key.
        //    This is the exact same payload the Observer would have built.
        // ------------------------------------------------------------------
        $this->warmUpRedis(
            keyHash:        $this->keyHash,
            status:         ApiKeyStatus::Active,
            rateLimitMax:   self::DEFAULT_RATE_LIMIT_MAX,
            rateLimitWindow: self::DEFAULT_RATE_LIMIT_WINDOW,
        );
    }

    protected function tearDown(): void
    {
        // Remove all Redis keys created during the test to keep test runs isolated.
        $this->cacheService->forget($this->keyHash ?? '');
        $this->deleteRateLimitKey($this->tenantId ?? '', $this->apiKeyId ?? 0);

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Scenario 1 — Missing header
    // -----------------------------------------------------------------------

    /**
     * A request with no X-API-KEY header must be rejected immediately with 401.
     */
    public function test_rejects_request_without_api_key_header(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertUnauthorized()
            ->assertJsonFragment(['error' => 'missing_api_key']);
    }

    // -----------------------------------------------------------------------
    // Scenario 2 — Unknown token (Redis miss)
    // -----------------------------------------------------------------------

    /**
     * A token with no corresponding Redis cache entry must return 401.
     * This covers the "unknown token" path without ever hitting MySQL.
     */
    public function test_rejects_request_with_unknown_api_key(): void
    {
        $unknownToken = 'unkn_00000000-0000-0000-0000-000000000000_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $response = $this->withHeader($this->config->apiKeyHeader, $unknownToken)
            ->getJson('/api/health');

        $response->assertUnauthorized()
            ->assertJsonFragment(['error' => 'invalid_api_key']);
    }

    // -----------------------------------------------------------------------
    // Scenario 3 — Revoked key
    // -----------------------------------------------------------------------

    /**
     * A token whose Redis cache payload has status='revoked' must return 401.
     * Revocation is simulated by overwriting the cache entry — no DB update needed.
     */
    public function test_rejects_revoked_api_key(): void
    {
        // Overwrite the active cache entry with a revoked status.
        $this->warmUpRedis(
            keyHash:        $this->keyHash,
            status:         ApiKeyStatus::Revoked,
            rateLimitMax:   self::DEFAULT_RATE_LIMIT_MAX,
            rateLimitWindow: self::DEFAULT_RATE_LIMIT_WINDOW,
        );

        $response = $this->withHeader($this->config->apiKeyHeader, $this->plainToken)
            ->getJson('/api/health');

        $response->assertUnauthorized()
            ->assertJsonFragment(['error' => 'invalid_api_key']);
    }

    // -----------------------------------------------------------------------
    // Scenario 4 — Valid key passes through
    // -----------------------------------------------------------------------

    /**
     * A request with a valid, active key must be forwarded and return 200.
     * X-RateLimit-* headers must be present on every successful response.
     */
    public function test_allows_valid_api_key(): void
    {
        $response = $this->withHeader($this->config->apiKeyHeader, $this->plainToken)
            ->getJson('/api/health');

        $response->assertOk()
            ->assertJsonFragment(['status' => 'ok']);

        // Rate-limit telemetry headers must always accompany 2xx responses.
        $response->assertHeader('X-RateLimit-Limit', (string) self::DEFAULT_RATE_LIMIT_MAX);
        $response->assertHeader('X-RateLimit-Remaining');
    }

    // -----------------------------------------------------------------------
    // Scenario 5 — Rate limit exceeded
    // -----------------------------------------------------------------------

    /**
     * After exhausting the per-key rate limit, subsequent requests return 429
     * with correct X-RateLimit-* and Retry-After headers.
     */
    public function test_returns_429_when_rate_limit_exceeded(): void
    {
        // Create a tightly-limited key (cap = 2) specifically for this scenario.
        $generator = app(TokenGenerator::class);
        $generated = $generator->generate();

        $tightModel = new ApiKey();
        $tightModel->forceFill([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Tight Key',
            'key_id'            => $generated->keyId,
            'key_hash'          => $generated->keyHash,
            'rate_limit_max'    => 2,
            'rate_limit_window' => 60,
            'status'            => ApiKeyStatus::Active->value,
            'expires_at'        => null,
        ]);
        $tightModel->saveQuietly(); // suppresses Observer → no Redis I/O for this fixture
        $tightKeyId = (int) $tightModel->id;

        $this->warmUpRedis(
            keyHash:        $generated->keyHash,
            status:         ApiKeyStatus::Active,
            rateLimitMax:   2,
            rateLimitWindow: 60,
            apiKeyId:       $tightKeyId,
        );

        $makeRequest = fn() => $this
            ->withHeader($this->config->apiKeyHeader, $generated->plainToken)
            ->getJson('/api/health');

        // Consume both allowed slots.
        $makeRequest()->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $makeRequest()->assertOk()->assertHeader('X-RateLimit-Remaining', '0');

        // Third request must be denied.
        $response = $makeRequest();

        $response->assertStatus(429)
            ->assertJsonFragment(['error' => 'rate_limit_exceeded'])
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0');

        // Retry-After must be a positive integer ≤ window size (60 s).
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        // Clean up this scenario's Redis keys.
        $this->cacheService->forget($generated->keyHash);
        $this->deleteRateLimitKey($this->tenantId, $tightKeyId);
    }

    // -----------------------------------------------------------------------
    // Scenario 6 — Usage log job dispatched post-response
    // -----------------------------------------------------------------------

    /**
     * After a successful request, terminate() must dispatch ProcessApiUsageLogs
     * to the analytics queue. Queue::fake() intercepts the dispatch for assertion.
     */
    public function test_dispatches_usage_log_job_after_response(): void
    {
        Queue::fake();

        $response = $this->withHeader($this->config->apiKeyHeader, $this->plainToken)
            ->getJson('/api/health');

        $response->assertOk();

        // Assert the correct job was pushed to the configured analytics queue.
        Queue::assertPushedOn(
            $this->config->analyticsQueue,
            ProcessApiUsageLogs::class
        );

        // Assert the job payload matches the request metadata.
        Queue::assertPushed(
            ProcessApiUsageLogs::class,
            function (ProcessApiUsageLogs $job): bool {
                return $job->entry->tenantId   === $this->tenantId
                    && $job->entry->apiKeyId   === $this->apiKeyId
                    && $job->entry->endpoint   === '/api/health'
                    && $job->entry->method     === 'GET'
                    && $job->entry->statusCode === 200
                    && $job->entry->responseTimeMs >= 0;
            }
        );
    }

    // -----------------------------------------------------------------------
    // Scenario 7 — Auth failure suppresses log dispatch
    // -----------------------------------------------------------------------

    /**
     * A request that fails authentication must NOT dispatch a usage log job.
     * terminate() bails early when request attributes are absent (auth-fail path).
     */
    public function test_does_not_dispatch_log_job_on_auth_failure(): void
    {
        Queue::fake();

        $this->getJson('/api/health')->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Store a cache payload directly in Redis for the given key hash.
     * Uses the same ApiKeyCacheService::put() that the production Observer
     * calls — no special test-only code paths.
     */
    private function warmUpRedis(
        string $keyHash,
        ApiKeyStatus $status,
        int $rateLimitMax,
        int $rateLimitWindow,
        ?int $apiKeyId = null,
    ): void {
        $payload = new ApiKeyCachePayload(
            tenantId:        $this->tenantId,
            apiKeyId:        $apiKeyId ?? $this->apiKeyId,
            rateLimitMax:    $rateLimitMax,
            rateLimitWindow: $rateLimitWindow,
            status:          $status,
        );

        // 300-second TTL is sufficient for any test scenario.
        $this->cacheService->put($keyHash, $payload, 300);
    }

    /**
     * Delete the sliding-window rate-limit sorted set for a specific key.
     * Prevents leftover sorted set entries from polluting subsequent test runs.
     */
    private function deleteRateLimitKey(string $tenantId, int $apiKeyId): void
    {
        if ($tenantId === '' || $apiKeyId === 0) {
            return;
        }

        $key = $this->config->rateLimitPrefix . $tenantId . ':' . $apiKeyId;
        Redis::connection($this->config->redisConnection)->del($key);
    }
}

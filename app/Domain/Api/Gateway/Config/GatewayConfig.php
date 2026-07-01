<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Config;

/**
 * Typed, immutable value object that wraps the raw api-gateway config array.
 *
 * All gateway services receive this via constructor injection, which means:
 *   - No direct config() calls deep in the domain layer.
 *   - When extracted to a standalone Composer package the host app supplies
 *     a concrete instance via the service container — zero code changes inside
 *     the package itself.
 */
final readonly class GatewayConfig
{
    /**
     * @param  string  $tenantModel         FQCN of the Tenant Eloquent model.
     * @param  string  $apiKeyModel         FQCN of the ApiKey Eloquent model.
     * @param  string  $apiUsageLogModel    FQCN of the ApiUsageLog Eloquent model.
     * @param  string  $tenantsTable        Database table name for tenants.
     * @param  string  $apiKeysTable        Database table name for api_keys.
     * @param  string  $apiUsageLogsTable   Database table name for api_usage_logs.
     * @param  string  $redisConnection     Named Redis connection (config/database.php).
     * @param  string  $apiKeyPrefix        Redis key prefix for cached API keys.
     * @param  string  $rateLimitPrefix     Redis key prefix for rate-limit sorted sets.
     * @param  string  $usageLogBufferKey   Redis list key used as the async log buffer.
     * @param  string  $queueConnection     Queue connection for analytics jobs.
     * @param  string  $analyticsQueue      Queue name for analytics processing.
     * @param  int     $usageLogBatchSize   How many log entries to flush per bulk insert.
     * @param  string  $tokenPrefix         Short prefix prepended to every generated token.
     * @param  string  $apiKeyHeader        HTTP header name carrying the raw API key.
     */
    public function __construct(
        // Model FQCNs
        public readonly string $tenantModel,
        public readonly string $apiKeyModel,
        public readonly string $apiUsageLogModel,

        // Table names
        public readonly string $tenantsTable,
        public readonly string $apiKeysTable,
        public readonly string $apiUsageLogsTable,

        // Redis settings
        public readonly string $redisConnection,
        public readonly string $apiKeyPrefix,
        public readonly string $rateLimitPrefix,
        public readonly string $usageLogBufferKey,

        // Queue settings
        public readonly string $queueConnection,
        public readonly string $analyticsQueue,

        // Usage log batching
        public readonly int $usageLogBatchSize,

        // Token generation
        public readonly string $tokenPrefix,

        // HTTP
        public readonly string $apiKeyHeader,
    ) {}

    /**
     * Build a GatewayConfig instance from the raw config array returned by
     * Laravel's config('api-gateway') helper.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            tenantModel:        $config['models']['tenant'],
            apiKeyModel:        $config['models']['api_key'],
            apiUsageLogModel:   $config['models']['api_usage_log'],

            tenantsTable:       $config['tables']['tenants'],
            apiKeysTable:       $config['tables']['api_keys'],
            apiUsageLogsTable:  $config['tables']['api_usage_logs'],

            redisConnection:    $config['redis']['connection'],
            apiKeyPrefix:       $config['redis']['api_key_prefix'],
            rateLimitPrefix:    $config['redis']['rate_limit_prefix'],
            usageLogBufferKey:  $config['redis']['usage_log_buffer_key'],

            queueConnection:    $config['queue']['connection'],
            analyticsQueue:     $config['queue']['analytics'],

            usageLogBatchSize:  (int) $config['usage_logs']['batch_size'],

            tokenPrefix:        $config['token']['prefix'],

            apiKeyHeader:       $config['header'],
        );
    }
}

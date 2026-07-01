<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Eloquent Model Class Map
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class names for each domain model. Override these in a
    | host-app config publish if you need custom model extensions.
    |
    */
    'models' => [
        'tenant'        => App\Domain\Api\Gateway\Models\Tenant::class,
        'api_key'       => App\Domain\Api\Gateway\Models\ApiKey::class,
        'api_usage_log' => App\Domain\Api\Gateway\Models\ApiUsageLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Names
    |--------------------------------------------------------------------------
    |
    | Allows the host application (or future package consumers) to rename
    | tables without touching migration files.
    |
    */
    'tables' => [
        'tenants'        => 'tenants',
        'api_keys'       => 'api_keys',
        'api_usage_logs' => 'api_usage_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | connection        — named connection defined in config/database.php redis section.
    | api_key_prefix    — key namespace for cached API key hashes.
    | rate_limit_prefix — key namespace for sliding-window sorted sets.
    | usage_log_buffer_key — Redis list used as an async write buffer.
    |
    */
    'redis' => [
        'connection'           => env('API_GATEWAY_REDIS_CONNECTION', 'default'),
        'api_key_prefix'       => 'api_keys:',
        'rate_limit_prefix'    => 'rate_limit:',
        'usage_log_buffer_key' => 'api_usage_logs:buffer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | The analytics queue is intentionally separate from the default queue so
    | it can be scaled or prioritised independently.
    |
    */
    'queue' => [
        'connection' => env('API_GATEWAY_QUEUE_CONNECTION', 'redis'),
        'analytics'  => 'api-analytics-processing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Log Batching
    |--------------------------------------------------------------------------
    |
    | Number of log entries accumulated in the Redis buffer before a
    | ProcessApiUsageLogs worker flushes them to MySQL in bulk.
    |
    */
    'usage_logs' => [
        'batch_size' => (int) env('API_GATEWAY_USAGE_LOG_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Generation
    |--------------------------------------------------------------------------
    |
    | prefix — short string prepended to every generated token for easy
    |           identification (e.g. "mtam_<uuid>_<random>").
    |
    */
    'token' => [
        'prefix' => env('API_GATEWAY_TOKEN_PREFIX', 'mtam'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Key HTTP Header
    |--------------------------------------------------------------------------
    |
    | The request header name the middleware reads the raw token from.
    |
    */
    'header' => 'X-API-KEY',

];

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the api_usage_logs table.
 *
 * Design decisions:
 *  - UUID primary key: log IDs may be exposed in audit exports; UUID avoids
 *    leaking row counts and is safer for distributed insert scenarios.
 *  - No foreign key constraints on tenant_id / api_key_id: this is intentional.
 *    Log entries are written asynchronously via the Redis buffer after the
 *    HTTP response is already sent. By the time the worker flushes, the source
 *    key could theoretically be revoked or deleted. Constraints would cause
 *    bulk inserts to fail. Referential integrity is enforced at the application
 *    layer instead (the middleware only logs after successful auth).
 *  - tenant_id and api_key_id are individually indexed to support per-tenant
 *    and per-key analytics queries efficiently.
 *  - requested_at indexed: primary dimension for time-range analytics queries.
 *  - No Laravel timestamps(): logs are append-only records. requested_at is
 *    the authoritative timestamp, set by the middleware (not the DB server).
 *  - payload_size_bytes defaults to 0: streaming or empty responses produce
 *    no content length; defaulting to 0 avoids NULLs in aggregation queries.
 *  - Table name resolved from config at runtime → loose coupling.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $table = config('api-gateway.tables.api_usage_logs', 'api_usage_logs');

        Schema::create($table, function (Blueprint $table): void {
            // UUID primary key — safe for external exposure in audit exports.
            $table->uuid('id')->primary();

            // Tenant identifier. Indexed for per-tenant analytics; no FK constraint
            // (see class-level docblock for rationale).
            $table->uuid('tenant_id')->index();

            // The specific API key that authenticated this request. Indexed for
            // per-key usage analytics; no FK constraint (same rationale as tenant_id).
            $table->unsignedBigInteger('api_key_id')->index();

            // Full request path, e.g. /api/v1/users/42. 512 chars covers deeply
            // nested REST paths while keeping the column width bounded.
            $table->string('endpoint', 512);

            // HTTP method in uppercase: GET, POST, PUT, PATCH, DELETE, etc.
            $table->string('method', 16);

            // HTTP status code returned to the client (100–599).
            // unsignedSmallInteger uses 2 bytes vs 4 bytes for INT — saves ~33 %
            // column storage on a potentially very large append-only table.
            $table->unsignedSmallInteger('status_code');

            // Total round-trip time in milliseconds from middleware entry to response.
            // Unsigned INT supports values up to ~49 days; sufficient for any timeout.
            $table->unsignedInteger('response_time_ms');

            // Size of the response body in bytes. Defaults to 0 for empty/streaming
            // responses to avoid NULL handling in SUM/AVG aggregation queries.
            $table->unsignedInteger('payload_size_bytes')->default(0);

            // Exact moment the request was received, set by the middleware.
            // Indexed as the primary time-series dimension for analytics range queries.
            // NOT NULL: every log entry must have a precise timestamp.
            $table->timestamp('requested_at')->index();

            // No created_at / updated_at — requested_at serves as the single
            // authoritative timestamp and the table is append-only.
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $table = config('api-gateway.tables.api_usage_logs', 'api_usage_logs');

        Schema::dropIfExists($table);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the api_keys table.
 *
 * Design decisions:
 *  - Bigint auto-increment PK: used as the Redis hash field 'api_key_id' and
 *    as the FK in api_usage_logs. Integer FK is faster for JOIN/lookup than UUID.
 *  - key_id  (CHAR 8, plain text, indexed): the visible "prefix" of the token
 *    used for key identification in admin UIs without exposing the secret.
 *  - key_hash (CHAR 64, SHA-256 hex, unique): the only value persisted from
 *    the secret portion; the plain token is returned once and discarded.
 *  - No updated_at: keys are immutable after creation except for status and
 *    expires_at. Tracking mutations is handled by the Observer + Redis.
 *  - Foreign key references tenants table dynamically via config to honour
 *    the loose coupling rule.
 *  - expires_at indexed: allows efficient sweep queries for expiry cleanup jobs.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $apiKeysTable = config('api-gateway.tables.api_keys', 'api_keys');
        $tenantsTable = config('api-gateway.tables.tenants', 'tenants');

        Schema::create($apiKeysTable, function (Blueprint $table) use ($tenantsTable): void {
            // Bigint auto-increment primary key — used as Redis hash value and
            // as the FK referenced by api_usage_logs.api_key_id.
            $table->id();

            // Owning tenant. Cascade delete: removing a tenant wipes all its keys.
            $table->foreignUuid('tenant_id')
                ->constrained($tenantsTable)
                ->cascadeOnDelete();

            // Human-readable label assigned by the tenant admin (e.g. "Production Key").
            $table->string('name');

            // First 8 characters of the plain token stored in plain text.
            // Used for key identification in logs/UI; not secret on its own.
            $table->char('key_id', 8)->index();

            // SHA-256 hex digest of the full plain token (64 chars).
            // This is what the middleware hashes and looks up in Redis.
            // Unique constraint prevents accidental hash collisions from being persisted.
            $table->char('key_hash', 64)->unique();

            // Per-key rate-limit ceiling (requests per window).
            $table->unsignedInteger('rate_limit_max')->default(1000);

            // Rate-limit window size in seconds (e.g. 60 = 1-minute window).
            $table->unsignedInteger('rate_limit_window')->default(60);

            // Lifecycle state: active | revoked. VARCHAR(16) matches ApiKeyStatus enum values.
            $table->string('status', 16)->default('active')->index();

            // Optional hard expiry timestamp. NULL means the key never expires.
            // Indexed to support efficient expiry sweep queries (e.g. nightly jobs).
            $table->timestamp('expires_at')->nullable()->index();

            // Creation timestamp only — keys are immutable records after creation.
            // updated_at is intentionally omitted; status changes are tracked via Observer.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $apiKeysTable = config('api-gateway.tables.api_keys', 'api_keys');

        Schema::dropIfExists($apiKeysTable);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tenants table.
 *
 * Design decisions:
 *  - UUID primary key: tenant IDs are exposed in Redis hashes and HTTP
 *    responses; integer auto-increments would leak row counts to clients.
 *  - status column: VARCHAR(32) to accommodate future enum expansions without
 *    an ALTER TABLE. Indexed for WHERE status = 'active' query scopes.
 *  - Table name resolved from config at runtime → loose coupling, safe to
 *    rename without touching this migration file.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $table = config('api-gateway.tables.tenants', 'tenants');

        Schema::create($table, function (Blueprint $table): void {
            // UUID primary key — no auto-increment, IDs are safe to expose externally.
            $table->uuid('id')->primary();

            // Human-readable display name for the tenant (e.g. company name).
            $table->string('name');

            // Lifecycle state: active | suspended | inactive.
            // VARCHAR(32) gives room for future states without a schema change.
            $table->string('status', 32)->default('active')->index();

            // Standard Laravel timestamps (created_at, updated_at).
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $table = config('api-gateway.tables.tenants', 'tenants');

        Schema::dropIfExists($table);
    }
};

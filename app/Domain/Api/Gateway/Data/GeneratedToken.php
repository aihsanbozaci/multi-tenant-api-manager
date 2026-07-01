<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Data;

/**
 * Immutable value object returned by TokenGenerator::generate().
 *
 * Bundles the three pieces of data that must be handled atomically:
 *   - plainToken  : the full secret token — shown to the tenant ONCE then discarded.
 *   - keyId       : first 8 characters of plainToken — stored in plain text for UI display.
 *   - keyHash     : SHA-256 hex digest of plainToken — the ONLY secret value persisted.
 *
 * The struct is intentionally minimal; consumers destructure what they need.
 */
final readonly class GeneratedToken
{
    public function __construct(
        /** Full plain-text token in {prefix}_{uuid}_{random} format. Never persisted. */
        public readonly string $plainToken,

        /** First 8 characters of $plainToken (plain, DB-indexed, non-secret prefix). */
        public readonly string $keyId,

        /** SHA-256 hex digest (64 chars) of $plainToken. Stored in MySQL + Redis. */
        public readonly string $keyHash,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Data;

/**
 * Immutable result returned by ApiKeyService::create().
 *
 * The plain token is the only piece of information the caller receives that
 * is never stored anywhere — not in MySQL, not in Redis, not in logs.
 * Once this object is returned and the response is sent to the tenant, the
 * plain token cannot be recovered; the tenant must store it themselves.
 *
 * All other fields are safe to persist, display in admin UIs, or log.
 */
final readonly class CreatedApiKeyResult
{
    public function __construct(
        /** Auto-increment primary key of the newly created api_keys row. */
        public readonly int $apiKeyId,

        /**
         * Full plain-text token in {prefix}_{uuid}_{random} format.
         * MUST be shown to the tenant exactly once and then discarded.
         * Not stored in the database or Redis.
         */
        public readonly string $plainToken,

        /** First 8 characters of plainToken — safe for display/logging. */
        public readonly string $keyId,

        /** SHA-256 hex digest (64 chars) — what is actually stored and cached. */
        public readonly string $keyHash,
    ) {}
}

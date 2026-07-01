<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Support;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Data\GeneratedToken;

/**
 * Generates cryptographically secure API tokens.
 *
 * Token format:  {prefix}_{uuid}_{random32hex}
 * Example:       mtam_550e8400-e29b-41d4-a716-446655440000_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4
 *
 * Component breakdown:
 *   {prefix}    — configurable short string (default: 'mtam') from api-gateway.php.
 *                 Makes tokens immediately identifiable in logs and support tickets.
 *   {uuid}      — RFC 4122 v4 UUID, provides structural uniqueness and entropy.
 *   {random32}  — 32 hex characters from random_bytes(16), adds 128 bits of
 *                 cryptographic randomness on top of the UUID.
 *
 * The total token length is:  len(prefix) + 1 + 36 + 1 + 32
 *   For prefix='mtam': 4 + 1 + 36 + 1 + 32 = 74 characters.
 *
 * Security properties:
 *   - SHA-256(plainToken) collision probability is negligible (~2^-128).
 *   - The plain token is never stored; only its SHA-256 hash survives.
 *   - random_bytes() is CSPRNG on all PHP platforms.
 */
final class TokenGenerator
{
    public function __construct(
        private readonly GatewayConfig $config,
    ) {}

    /**
     * Generate a new unique API token.
     *
     * Returns a GeneratedToken containing:
     *   - plainToken : the full secret — must be shown once and discarded.
     *   - keyId      : first 8 characters — safe non-secret identifier.
     *   - keyHash    : SHA-256 hex digest — the only value persisted.
     *
     * @throws \Random\RandomException If the CSPRNG is unavailable (PHP 8.2+).
     */
    public function generate(): GeneratedToken
    {
        // Build the token in three parts separated by underscores.
        $prefix  = $this->config->tokenPrefix;
        $uuid    = $this->generateUuid();
        $entropy = bin2hex(random_bytes(16)); // 32 hex characters, 128-bit entropy

        $plainToken = sprintf('%s_%s_%s', $prefix, $uuid, $entropy);

        return new GeneratedToken(
            plainToken: $plainToken,
            keyId:      substr($plainToken, 0, 8),
            keyHash:    hash('sha256', $plainToken),
        );
    }

    /**
     * Generate a RFC 4122 version 4 UUID without depending on external packages.
     *
     * Uses random_bytes() for all 128 bits of randomness, then masks the
     * version (4) and variant (10xx) bits per the UUID spec.
     *
     * @throws \Random\RandomException If the CSPRNG is unavailable.
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        // Set version to 4 (0100xxxx in byte 6)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);

        // Set variant to RFC 4122 (10xxxxxx in byte 8)
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Data;

use Carbon\CarbonImmutable;

/**
 * Typed value object representing a single API usage log event.
 *
 * Lifecycle:
 *   1. Created inside ApiGatewayGuardMiddleware::terminate() after the HTTP
 *      response has been sent to the client.
 *   2. JSON-serialised and pushed to the Redis list buffer (api_usage_logs:buffer).
 *   3. De-serialised by ProcessApiUsageLogs::handle() and bulk-inserted into
 *      the api_usage_logs MySQL table.
 *
 * Immutability: readonly ensures no accidental mutation between creation and
 * persistence. The object is effectively a data transfer record.
 */
final readonly class ApiUsageLogEntry
{
    public function __construct(
        /** UUID of the tenant whose API key was used. */
        public readonly string $tenantId,

        /** Bigint primary key of the specific ApiKey that authenticated. */
        public readonly int $apiKeyId,

        /** Full request path, e.g. /api/v1/users (max 512 chars). */
        public readonly string $endpoint,

        /** HTTP method in uppercase, e.g. GET, POST, DELETE. */
        public readonly string $method,

        /** HTTP status code returned to the client, e.g. 200, 404, 429. */
        public readonly int $statusCode,

        /** Total round-trip time measured from middleware entry to response in milliseconds. */
        public readonly int $responseTimeMs,

        /** Size of the response body in bytes (0 when empty or streaming). */
        public readonly int $payloadSizeBytes,

        /** Exact moment the request was received (UTC). */
        public readonly CarbonImmutable $requestedAt,
    ) {}

    // -----------------------------------------------------------------------
    // Factory
    // -----------------------------------------------------------------------

    /**
     * Reconstruct an entry from a JSON string stored in the Redis list buffer.
     *
     * @throws \JsonException When the JSON is malformed.
     * @throws \InvalidArgumentException When required fields are absent.
     */
    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        $required = [
            'tenant_id', 'api_key_id', 'endpoint', 'method',
            'status_code', 'response_time_ms', 'payload_size_bytes', 'requested_at',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new \InvalidArgumentException(
                    "ApiUsageLogEntry JSON is missing required field: {$field}"
                );
            }
        }

        return new self(
            tenantId:         (string) $data['tenant_id'],
            apiKeyId:         (int)    $data['api_key_id'],
            endpoint:         (string) $data['endpoint'],
            method:           (string) $data['method'],
            statusCode:       (int)    $data['status_code'],
            responseTimeMs:   (int)    $data['response_time_ms'],
            payloadSizeBytes: (int)    $data['payload_size_bytes'],
            requestedAt:      CarbonImmutable::parse($data['requested_at']),
        );
    }

    // -----------------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------------

    /**
     * Serialise this entry to a JSON string for storage in the Redis buffer.
     *
     * @throws \JsonException On encoding failure.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Convert to a flat associative array suitable for DB::table()->insert().
     * Keys match the api_usage_logs column names exactly.
     * The 'id' column is intentionally omitted — it is a BIGINT AUTO_INCREMENT
     * primary key that MySQL generates automatically on insert.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'tenant_id'          => $this->tenantId,
            'api_key_id'         => $this->apiKeyId,
            'endpoint'           => $this->endpoint,
            'method'             => $this->method,
            'status_code'        => $this->statusCode,
            'response_time_ms'   => $this->responseTimeMs,
            'payload_size_bytes' => $this->payloadSizeBytes,
            'requested_at'       => $this->requestedAt->toDateTimeString(),
        ];
    }
}

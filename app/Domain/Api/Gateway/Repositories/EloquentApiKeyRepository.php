<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Repositories;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Contracts\ApiKeyRepositoryInterface;
use App\Domain\Api\Gateway\Data\ApiKeyCachePayload;
use App\Domain\Api\Gateway\Enums\ApiKeyStatus;
use App\Domain\Api\Gateway\Models\ApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent-backed implementation of ApiKeyRepositoryInterface.
 *
 * The model class is resolved from GatewayConfig rather than hard-coded so the
 * host application can substitute a custom ApiKey model via config override —
 * a critical requirement for the package-extraction scenario.
 *
 * IMPORTANT — Hot-path rule enforcement:
 *   None of these methods are called during the request auth flow. The
 *   middleware reads exclusively from Redis (via ApiKeyCacheService). These
 *   methods are only invoked by ApiKeyService for write operations and by
 *   ApiKeyObserver for cache warm-up.
 */
class EloquentApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(
        private readonly GatewayConfig $config,
    ) {}

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Returns a fresh query builder scoped to the configured model class.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->config->apiKeyModel;

        return $modelClass::query();
    }

    /**
     * Instantiate a new (unsaved) model instance.
     */
    private function newModel(): Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->config->apiKeyModel;

        return new $modelClass();
    }

    /**
     * Build an ApiKeyCachePayload from a raw Eloquent model instance.
     * Explicit casts guard against models without cast definitions.
     */
    private function payloadFromModel(Model $model): ApiKeyCachePayload
    {
        $status = $model->status;

        return new ApiKeyCachePayload(
            tenantId:        (string) $model->tenant_id,
            apiKeyId:        (int)    $model->id,
            rateLimitMax:    (int)    $model->rate_limit_max,
            rateLimitWindow: (int)    $model->rate_limit_window,
            status:          $status instanceof ApiKeyStatus
                                 ? $status
                                 : ApiKeyStatus::from((string) $status),
        );
    }

    // -----------------------------------------------------------------------
    // ApiKeyRepositoryInterface implementation
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function create(
        string $tenantId,
        string $name,
        string $keyId,
        string $keyHash,
        int $rateLimitMax,
        int $rateLimitWindow,
        ?CarbonImmutable $expiresAt,
    ): int {
        $model = $this->newModel();

        // Use forceFill to bypass $fillable guards when setting system fields.
        $model->forceFill([
            'tenant_id'         => $tenantId,
            'name'              => $name,
            'key_id'            => $keyId,
            'key_hash'          => $keyHash,
            'rate_limit_max'    => $rateLimitMax,
            'rate_limit_window' => $rateLimitWindow,
            'status'            => ApiKeyStatus::Active->value,
            'expires_at'        => $expiresAt,
        ])->save();

        return (int) $model->id;
    }

    /**
     * {@inheritdoc}
     */
    public function findCachePayloadById(int $apiKeyId): ?ApiKeyCachePayload
    {
        $record = $this->query()
            ->select(['id', 'tenant_id', 'rate_limit_max', 'rate_limit_window', 'status'])
            ->find($apiKeyId);

        return $record !== null ? $this->payloadFromModel($record) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findCachePayloadByHash(string $keyHash): ?ApiKeyCachePayload
    {
        $record = $this->query()
            ->select(['id', 'tenant_id', 'rate_limit_max', 'rate_limit_window', 'status'])
            ->where('key_hash', $keyHash)
            ->first();

        return $record !== null ? $this->payloadFromModel($record) : null;
    }

    /**
     * {@inheritdoc}
     *
     * IMPORTANT: Uses Eloquent model save() (not Builder::update()) so the
     * 'updated' model event fires and ApiKeyObserver::updated() syncs Redis.
     * A raw Builder::update() call would bypass all model events silently.
     */
    public function updateStatus(int $apiKeyId, ApiKeyStatus $status): void
    {
        $model = $this->query()->find($apiKeyId);

        if ($model === null) {
            return;
        }

        // Setting the cast field with the enum value; Eloquent serialises it
        // to the string value when persisting (ApiKeyStatus backed enum).
        $model->status = $status;

        // save() triggers the 'updated' Eloquent event → ApiKeyObserver fires.
        $model->save();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $apiKeyId): void
    {
        // Eloquent delete triggers the 'deleting'/'deleted' model events.
        // ApiKeyObserver::deleted() will invalidate the Redis cache entry.
        $model = $this->query()->find($apiKeyId);

        if ($model !== null) {
            $model->delete();
        }
    }
}

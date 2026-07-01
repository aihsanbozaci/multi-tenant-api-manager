<?php

declare(strict_types=1);

namespace App\Domain\Api\Gateway\Providers;

use App\Domain\Api\Gateway\Config\GatewayConfig;
use App\Domain\Api\Gateway\Contracts\ApiKeyRepositoryInterface;
use App\Domain\Api\Gateway\Contracts\ApiUsageLogRepositoryInterface;
use App\Domain\Api\Gateway\Observers\ApiKeyObserver;
use App\Domain\Api\Gateway\Repositories\EloquentApiKeyRepository;
use App\Domain\Api\Gateway\Repositories\EloquentApiUsageLogRepository;
use App\Domain\Api\Gateway\Services\ApiKeyCacheService;
use App\Domain\Api\Gateway\Services\ApiKeyService;
use App\Domain\Api\Gateway\Services\SlidingWindowRateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Single host-application entry point for the API Gateway module.
 *
 * Responsibilities:
 *  - Merges the module's default config so it can be overridden by a publish.
 *  - Binds all interfaces and singletons into the service container.
 *  - Registers the ApiKeyObserver against the model class resolved from config
 *    (fully dynamic — no hard dependency on a specific model class here).
 *  - Loads package-local migrations so php artisan migrate just works.
 *
 * Package-conversion note:
 *  - Replace mergeConfigFrom() path with the package-relative config path.
 *  - Add $this->publishes([...]) for config, migrations, etc.
 *  - Remove from bootstrap/providers.php; add to composer.json
 *    extra.laravel.providers for auto-discovery.
 */
class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package defaults — host app config/api-gateway.php takes precedence.
        // Path breakdown from Providers/ → up 5 levels → project root → config/
        // Providers/ → Gateway/ → Api/ → Domain/ → app/ → project-root/ → config/
        $this->mergeConfigFrom(
            __DIR__ . '/../../../../../config/api-gateway.php',
            'api-gateway'
        );

        // Bind the typed config DTO as a singleton so it is constructed only once.
        $this->app->singleton(GatewayConfig::class, static function (): GatewayConfig {
            /** @var array<string, mixed> $raw */
            $raw = config('api-gateway');

            return GatewayConfig::fromArray($raw);
        });

        // Repository interface → Eloquent implementation bindings.
        $this->app->bind(
            ApiKeyRepositoryInterface::class,
            EloquentApiKeyRepository::class
        );

        $this->app->bind(
            ApiUsageLogRepositoryInterface::class,
            EloquentApiUsageLogRepository::class
        );

        // Support class singletons — built once, shared across all services.
        $this->app->singleton(\App\Domain\Api\Gateway\Support\TokenGenerator::class);
        $this->app->singleton(\App\Domain\Api\Gateway\Support\RedisKeyBuilder::class);

        // Service singletons — constructed once per request lifecycle.
        $this->app->singleton(ApiKeyCacheService::class);
        $this->app->singleton(SlidingWindowRateLimiter::class);
        $this->app->singleton(ApiKeyService::class);
    }

    public function boot(): void
    {
        // Load migrations from the domain layer so they run with php artisan migrate
        // without needing to copy files to database/migrations/.
        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );

        // Register the observer against whichever model class is configured.
        // Resolving the class string from config keeps this provider free of
        // any direct import of the concrete ApiKey model — pure loose coupling.
        /** @var class-string $apiKeyModelClass */
        $apiKeyModelClass = config('api-gateway.models.api_key');

        $apiKeyModelClass::observe(ApiKeyObserver::class);
    }
}

<?php

declare(strict_types=1);

use App\Domain\Api\Gateway\Providers\GatewayServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    GatewayServiceProvider::class,
];

<?php

declare(strict_types=1);

namespace Modules\Importer\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Importer\Services\ErrorService;

final class ImporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorService::class);
    }

    public function boot(): void {}
}

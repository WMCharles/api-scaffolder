<?php

declare(strict_types=1);

namespace CharlesMasinde\ApiScaffolder;

use CharlesMasinde\ApiScaffolder\Console\Commands\MakeApiModule;
use Illuminate\Support\ServiceProvider;

class ApiScaffolderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/api-scaffolder.php',
            'api-scaffolder',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeApiModule::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/api-scaffolder.php' => config_path('api-scaffolder.php'),
            ], 'api-scaffolder-config');

            $this->publishes([
                __DIR__ . '/Stubs' => base_path('stubs/api-scaffolder'),
            ], 'api-scaffolder-stubs');
        }
    }
}
<?php

namespace CharlesMasinde\ApiScaffolder;

use Illuminate\Support\ServiceProvider;
use CharlesMasinde\ApiScaffolder\Console\Commands\MakeApiModule;

class ApiScaffolderServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeApiModule::class,
            ]);
        }
    }
}
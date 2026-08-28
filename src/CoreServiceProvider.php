<?php

namespace Invue\Core;

use Illuminate\Support\ServiceProvider;
use Invue\Core\Console\Commands\InstallCommand;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}

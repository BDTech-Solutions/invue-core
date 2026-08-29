<?php

namespace Invue\Core;

use Illuminate\Support\ServiceProvider;
use Invue\Core\Console\Commands\InstallCommand;
use Invue\Core\Console\Commands\MakeUserCommand;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                MakeUserCommand::class,
            ]);
        }
    }
}

<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;

class ApiServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        $this->setupConfig();
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerApiHelper($this->app);
    }

    /**
     * Setup the config.
     *
     * @return void
     */
    protected function setupConfig()
    {
        $configPath = __DIR__ . '/../config/apihelper.php';
        $this->publishes([$configPath => config_path('apihelper.php')], 'config');
        $this->mergeConfigFrom($configPath, 'apihelper');
    }

    /**
     * Register the helper class.
     *
     * @param \Illuminate\Contracts\Foundation\Application  $app
     *
     * @return void
     */
    protected function registerApiHelper(Application $app)
    {
        $app->singleton('apihelper', function ($app) {
            $request = $app['request'];
            $config = $app['config'];
            return new Factory($request, $config);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return string[]
     */
    public function provides()
    {
        return ['apihelper'];
    }
}
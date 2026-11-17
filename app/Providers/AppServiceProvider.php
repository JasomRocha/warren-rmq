<?php

namespace App\Providers;

use App\Rabbit\Catalog;
use App\Rabbit\InstanceResolver;
use App\Warren\Brokers;
use App\Warren\MetricSampler;
use App\Warren\Supervisor;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared by every broker: how to read messages and the business names (the catalog) are one for the install.
        $this->app->singleton(Catalog::class, fn () => new Catalog(config('warren.catalog.path')));

        $this->app->singleton(InstanceResolver::class, fn () => new InstanceResolver(
            config('warren.identify.instance_paths'),
            config('warren.identify.instance_headers'),
            config('warren.identify.instance_regexes'),
            config('warren.identify.instance_normalize'),
        ));

        $this->app->singleton(MetricSampler::class);

        // The process behind `warren:tap`: keeps every connection listened to and sampled.
        $this->app->singleton(Supervisor::class, fn ($app) => new Supervisor(
            $app->make(Brokers::class),
            $app->make(MetricSampler::class),
            (bool) config('warren.amqp.enabled'),
        ));

        // Everything that talks to a broker is built per broker (see Brokers).
        $this->app->singleton(Brokers::class, fn ($app) => new Brokers(
            $app->make(Catalog::class),
            $app->make(InstanceResolver::class),
            $app->make(Cache::class),
        ));
    }

    public function boot(): void
    {
        //
    }
}

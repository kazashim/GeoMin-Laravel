<?php

namespace GeoMin\Providers;

use GeoMin\Algorithms\Anomaly\IsolationForestDetector;
use GeoMin\Algorithms\Anomaly\RXDetector;
use GeoMin\Algorithms\Anomaly\LocalOutlierFactorDetector;
use GeoMin\Algorithms\CloudMasking\CloudMasker;
use GeoMin\Algorithms\Mineralogy\AdvancedMineralogy;
use GeoMin\Algorithms\Spectral\SpectralCalculator;
use GeoMin\Clients\STACClient;
use GeoMin\Services\GeoMinService;
use Illuminate\Support\ServiceProvider;

/**
 * GeoMin Service Provider
 * 
 * Registers all GeoMin services with the Laravel application,
 * including clients, algorithms, and background job bindings.
 */
class GeoMinServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * This method is called before any other service method.
     * Bind interfaces to implementations here.
     */
    public function register(): void
    {
        // Register configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/geomin.php',
            'geomin'
        );

        // Register STAC Client
        $this->app->singleton(STACClient::class, function ($app) {
            return new STACClient(
                config('geomin.stac.endpoint', 'aws'),
                config('geomin.stac.api_key')
            );
        });

        // Register Cloud Masker
        $this->app->singleton(CloudMasker::class, function ($app) {
            return new CloudMasker(
                config('geomin.processing.default_algorithm', 'threshold')
            );
        });

        // Register Spectral Calculator
        $this->app->singleton(SpectralCalculator::class, function ($app) {
            return new SpectralCalculator();
        });

        // Register Advanced Mineralogy
        $this->app->singleton(AdvancedMineralogy::class, function ($app) {
            return new AdvancedMineralogy();
        });

        // Register Anomaly Detectors
        $this->app->singleton(IsolationForestDetector::class, function ($app) {
            return new IsolationForestDetector(
                config('geomin.anomaly.isolation_forest.trees', 100),
                config('geomin.anomaly.isolation_forest.contamination', 0.01)
            );
        });

        $this->app->singleton(RXDetector::class, function ($app) {
            return new RXDetector(
                config('geomin.anomaly.rx.threshold', 0.99)
            );
        });

        $this->app->singleton(LocalOutlierFactorDetector::class, function ($app) {
            return new LocalOutlierFactorDetector(
                config('geomin.anomaly.lof.neighbors', 20),
                config('geomin.anomaly.lof.contamination', 0.01)
            );
        });

        // Register main GeoMin Service (facade target)
        $this->app->singleton(GeoMinService::class, function ($app) {
            return new GeoMinService(
                $app->make(STACClient::class),
                $app->make(CloudMasker::class),
                $app->make(SpectralCalculator::class),
                $app->make(AdvancedMineralogy::class),
                $app->make(IsolationForestDetector::class),
                $app->make(RXDetector::class),
                $app->make(LocalOutlierFactorDetector::class)
            );
        });

        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Bootstrap any application services.
     *
     * This method is called after all services are registered.
     * Use it to register console commands and event listeners.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/geomin.php' => config_path('geomin.php'),
        ], 'geomin-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'geomin-migrations');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \GeoMin\Console\Commands\FetchSatelliteData::class,
                \GeoMin\Console\Commands\CalculateIndex::class,
                \GeoMin\Console\Commands\DetectAnomalies::class,
                \GeoMin\Console\Commands\RunMineralMapping::class,
                \GeoMin\Console\Commands\ApplyCloudMask::class,
            ]);
        }
    }

    /**
     * Register event listeners for GeoMin events.
     */
    protected function registerEventListeners(): void
    {
        // Event::listen(
        //     \GeoMin\Events\AnalysisCompleted::class,
        //     \GeoMin\Listeners\NotifyAnalysisCompletion::class
        // );
    }
}

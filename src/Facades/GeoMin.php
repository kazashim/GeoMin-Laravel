<?php

namespace GeoMin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * GeoMin Facade
 * 
 * Provides a static interface to the main GeoMin service,
 * coordinating access to all GeoMin functionality including
 * satellite data access, anomaly detection, and mineral mapping.
 * 
 * @see \GeoMin\Services\GeoMinService
 * 
 * @author Kazashim Kuzasuwat
 */
class GeoMin extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'geomin';
    }
}

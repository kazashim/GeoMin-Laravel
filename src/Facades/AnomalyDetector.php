<?php

namespace GeoMin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Anomaly Detector Facade
 * 
 * Provides a static interface to anomaly detection algorithms
 * for identifying potential mineral deposits and mining activity.
 * 
 * @see \GeoMin\Algorithms\Anomaly\IsolationForestDetector
 * 
 * @author Kazashim Kuzasuwat
 */
class AnomalyDetector extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \GeoMin\Algorithms\Anomaly\IsolationForestDetector::class;
    }
}

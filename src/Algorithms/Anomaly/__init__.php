<?php

/**
 * GeoMin Anomaly Detection Algorithms
 * 
 * This namespace contains implementations of anomaly detection
 * algorithms for identifying spectral anomalies in satellite imagery.
 */

use GeoMin\Algorithms\Anomaly\IsolationForestDetector;
use GeoMin\Algorithms\Anomaly\RXDetector;
use GeoMin\Algorithms\Anomaly\LocalOutlierFactorDetector;

class_alias(IsolationForestDetector::class, 'GeoMin\Anomaly\IsolationForest');
class_alias(RXDetector::class, 'GeoMin\Anomaly\RX');
class_alias(LocalOutlierFactorDetector::class, 'GeoMin\Anomaly\LOF');

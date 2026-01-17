<?php

/**
 * GeoMin Configuration
 * 
 * This configuration file controls all aspects of the GeoMin library,
 * including API endpoints, processing parameters, and algorithm settings.
 * 
 * @author Kazashim Kuzasuwat
 */

return [
    
    /*
    |--------------------------------------------------------------------------
    | STAC Client Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for connecting to SpatioTemporal Asset Catalog endpoints.
    | GeoMin supports multiple STAC providers including AWS Earth Search,
    | Microsoft Planetary Computer, and custom endpoints.
    |
    */
    'stac' => [
        // Default endpoint: 'aws', 'planetary_computer', 'element84', 'usgs', or custom URL
        'endpoint' => env('GEOMIN_STAC_ENDPOINT', 'aws'),
        
        // API key or token for authenticated endpoints
        'api_key' => env('GEOMIN_STAC_API_KEY'),
        
        // Default collection to search
        'default_collection' => env('GEOMIN_STAC_COLLECTION', 'sentinel-2-l2a'),
        
        // Request timeout in seconds
        'timeout' => 60,
        
        // Maximum number of results per request
        'max_results' => 100,
        
        // Enable request caching
        'cache_enabled' => true,
        
        // Cache duration in minutes
        'cache_ttl' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anomaly Detection Configuration
    |--------------------------------------------------------------------------
    |
    | Parameters for machine learning-based anomaly detection algorithms.
    | These settings control how the system identifies spectral anomalies
    | that may indicate mineral deposits or mining activity.
    |
    */
    'anomaly' => [
        // Isolation Forest settings
        'isolation_forest' => [
            'trees' => 100,           // Number of trees in the forest
            'contamination' => 0.01,  // Expected proportion of anomalies (0.0 - 1.0)
            'max_samples' => 'auto',  // Number of samples to use
        ],
        
        // RX (Reed-Xiaoli) Detector settings
        'rx' => [
            'threshold' => 0.99,      // Percentile threshold for anomaly detection
            'use_global' => true,     // Use global statistics vs local window
            'window_size' => null,    // Local window size (null = full image)
        ],
        
        // Local Outlier Factor settings
        'lof' => [
            'neighbors' => 20,        // Number of neighbors to consider
            'contamination' => 0.01,  // Expected proportion of anomalies
            'novelty' => false,       // Support novelty detection
        ],
        
        // Mahalanobis Distance settings
        'mahalanobis' => [
            'threshold' => 0.99,      // Chi-squared percentile threshold
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud Masking Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for cloud detection and removal algorithms.
    | GeoMin supports multiple algorithms optimized for different sensors.
    |
    */
    'cloud_masking' => [
        // Default algorithm: 'threshold', 'sentinel2', 'landsat_qa'
        'default_algorithm' => 'sentinel2',
        
        // Sentinel-2 specific thresholds
        'sentinel2' => [
            'blue_threshold' => 0.3,
            'nir_threshold' => 0.4,
            'swir_ratio_threshold' => 0.75,
            'cloud_confidence' => 0.4,
            'cirrus_threshold' => 0.01,
        ],
        
        // Generic threshold settings
        'threshold' => [
            'blue_threshold' => 0.3,
            'nir_threshold' => 0.4,
            'swir_ratio_threshold' => 0.75,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mineralogy Algorithm Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for advanced mineral detection algorithms including
    | Crosta PCA, Spectral Angle Mapper, and Linear Spectral Unmixing.
    |
    */
    'mineralogy' => [
        // Crosta PCA settings
        'crosta_pca' => [
            'n_components' => 4,
            'target_mineral' => 'hydroxyl',  // 'hydroxyl', 'iron', 'silica'
        ],
        
        // Spectral Angle Mapper settings
        'sam' => [
            'threshold' => 0.1,       // Default matching threshold (radians)
            'normalize' => true,      // Normalize spectra before comparison
        ],
        
        // Spectral Unmixing settings
        'unmixing' => [
            'method' => 'lsu',        // 'lsu' (linear spectral unmixing)
            'sum_to_one' => true,     // Constrain abundances to sum to 1
            'non_negative' => true,   // Constrain abundances to be non-negative
        ],
        
        // Reference spectral library path
        'spectral_library_path' => storage_path('geomin/spectra'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | General processing settings for image operations and memory management.
    |
    */
    'processing' => [
        // Default algorithm for basic spectral indices
        'default_algorithm' => 'numpy',
        
        // Memory limit for large operations (in MB)
        'memory_limit' => 2048,
        
        // Enable parallel processing
        'parallel' => true,
        
        // Number of worker processes
        'workers' => 4,
        
        // Chunk size for large arrays (in pixels)
        'chunk_size' => 1000000,
        
        // Output format: 'geotiff', 'png', 'jpg', 'array'
        'output_format' => 'geotiff',
        
        // Data type for output: 'float32', 'uint16', 'uint8'
        'output_dtype' => 'float32',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for storing satellite data and processing results.
    |
    */
    'storage' => [
        // Disk to use for satellite data
        'satellite_disk' => 'local',
        
        // Disk to use for results
        'results_disk' => 'local',
        
        // Base path for GeoMin data
        'base_path' => storage_path('geomin'),
        
        // Temporary directory for processing
        'temp_path' => storage_path('geomin/tmp'),
        
        // Automatically clean up temp files
        'auto_cleanup' => true,
        
        // Days to keep temp files
        'temp_ttl' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for background job processing of heavy operations.
    |
    */
    'queue' => [
        // Queue connection to use
        'connection' => 'redis',
        
        // Queue name
        'queue' => 'geomin',
        
        // Job timeout in seconds
        'timeout' => 3600,
        
        // Job retries on failure
        'max_retries' => 3,
        
        // Delay between retries in seconds
        'retry_delay' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for logging processing operations and errors.
    |
    */
    'logging' => {
        'enabled' => true,
        
        // Log level: 'debug', 'info', 'warning', 'error'
        'level' => 'info',
        
        // Channel to log to
        'channel' => 'stack',
    },

];

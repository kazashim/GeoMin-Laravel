# GeoMin Laravel

![GeoMin Laravel](https://img.shields.io/packagist/v/geomin/geomin-laravel.svg)
![License](https://img.shields.io/packagist/license/geomin/geomin-laravel.svg)
![PHP](https://img.shields.io/packagist/php-v/geomin/geomin-laravel.svg)

Laravel geophysics library for satellite-based mining activity detection and mineral identification. This package provides a comprehensive set of tools for analyzing satellite imagery to identify potential mineral deposits and mining activity.

## Features

- **STAC Client**: Query and download satellite imagery from multiple providers (AWS Earth Search, Microsoft Planetary Computer, etc.)
- **Anomaly Detection**: Machine learning algorithms to identify spectral anomalies (Isolation Forest, RX Detector, Local Outlier Factor)
- **Cloud Masking**: Automatic cloud detection and removal for preprocessing
- **Advanced Mineralogy**: Crosta PCA, Spectral Angle Mapper (SAM), and Linear Spectral Unmixing
- **Spectral Indices**: Calculate common indices (NDVI, NDWI, Iron Oxide Ratio, Clay Ratio, etc.)
- **Queue Support**: Heavy processing jobs can be dispatched to queues for background execution
- **Eloquent Integration**: Store and track analysis results in the database

## Requirements

- PHP 8.1 or higher
- Laravel 10 or 11
- Composer
- Extension: ext-json, ext-curl, ext-gd

## Installation

1. Install via Composer:

```bash
composer require geomin/geomin-laravel
```

2. Publish the configuration:

```bash
php artisan vendor:publish --provider="GeoMin\Providers\GeoMinServiceProvider"
```

3. Run the migrations:

```bash
php artisan migrate
```

## Configuration

Configure GeoMin by editing `config/geomin.php`:

```php
return [
    // STAC Client Configuration
    'stac' => [
        'endpoint' => env('GEOMIN_STAC_ENDPOINT', 'aws'),
        'api_key' => env('GEOMIN_STAC_API_KEY'),
        'default_collection' => 'sentinel-2-l2a',
        'timeout' => 60,
        'max_results' => 100,
        'cache_enabled' => true,
        'cache_ttl' => 60,
    ],

    // Anomaly Detection
    'anomaly' => [
        'isolation_forest' => [
            'trees' => 100,
            'contamination' => 0.01,
        ],
        'rx' => [
            'threshold' => 0.99,
            'window_size' => null,
        ],
        'lof' => [
            'neighbors' => 20,
            'contamination' => 0.01,
        ],
    ],

    // Cloud Masking
    'cloud_masking' => [
        'default_algorithm' => 'sentinel2',
        'sentinel2' => [
            'blue_threshold' => 0.3,
            'nir_threshold' => 0.4,
            'swir_ratio_threshold' => 0.75,
        ],
    ],

    // Queue Configuration
    'queue' => [
        'connection' => 'redis',
        'queue' => 'geomin',
        'timeout' => 3600,
        'max_retries' => 3,
    ],
];
```

## Usage

### Using the Facade

```php
use GeoMin\Facades\GeoMin;

// Search for satellite imagery
$results = GeoMin::stac()
    ->collection('sentinel-2-l2a')
    ->bbox([115.0, -32.0, 115.5, -31.5])
    ->date('2023-01-01', '2023-12-31')
    ->cloudCover(10)
    ->get();

// Calculate spectral index
$result = GeoMin::spectral()->calculateIndex($bands, 'ndvi');

// Detect anomalies
$result = GeoMin::detectAnomalies($imageData, 'isolation_forest', [
    'contamination' => 0.02,
]);

// Apply cloud masking
$result = GeoMin::cloudMasker()->mask($imageData, 'sentinel2');

// Perform Crosta PCA
$result = GeoMin::crostaPCA($imageData, 'hydroxyl');
```

### CLI Commands

GeoMin provides several Artisan commands for command-line usage:

#### Fetch Satellite Data

```bash
# Search for imagery in a bounding box
php artisan geomin:fetch --bbox="[115.0,-32.0,115.5,-31.5]" --cloud-cover=10

# Search with date range
php artisan geomin:fetch --date="2023-01-01/2023-12-31" --collection="sentinel-2-l2a"

# Dry run (show results without downloading)
php artisan geomin:fetch --bbox="[115.0,-32.0,115.5,-31.5]" --dry-run
```

#### Calculate Spectral Index

```bash
# Calculate NDVI
php artisan geomin:index path/to/bands.json --index=NDVI

# Calculate multiple indices
php artisan geomin:index path/to/bands.json --index=iron_oxide,clay --output=/path/to/results

# Save as CSV
php artisan geomin:index path/to/bands.json --index=NDVI --format=csv
```

#### Detect Anomalies

```bash
# Using RX Detector
php artisan geomin:detect path/to/image.json --algorithm=rx

# Using Isolation Forest
php artisan geomin:detect path/to/image.json --algorithm=isolation_forest --contamination=0.02

# Dispatch to queue
php artisan geomin:detect path/to/image.json --algorithm=rx --queue
```

#### Mineral Mapping

```bash
# Crosta PCA for hydroxyl alteration
php artisan geomin:mineral path/to/image.json --method=crosta --target=hydroxyl

# Spectral Angle Mapper
php artisan geomin:mineral path/to/image.json --method=sam --mineral=kaolinite

# Spectral Unmixing
php artisan geomin:mineral path/to/image.json --method=unmixing --endmembers=kaolinite,hematite
```

#### Cloud Masking

```bash
# Apply cloud masking
php artisan geomin:mask path/to/image.json --algorithm=sentinel2

# Generate cloud probability visualization
php artisan geomin:mask path/to/image.json --visualize --output=/path/to/results
```

### Working with Results

```php
use GeoMin\Models\SpatialAnalysis;

// Get analysis by ID
$analysis = SpatialAnalysis::find(1);

// Check status
if ($analysis->isCompleted()) {
    $result = $analysis->getResult();
    
    // Access statistics
    $stats = $result['statistics'] ?? [];
    
    // Access anomaly locations
    $locations = $result['top_locations'] ?? [];
}

// Get user's analyses
$analyses = SpatialAnalysis::forUser(auth()->id())
    ->recent(7)
    ->get();
```

### Queue Processing

For large datasets, dispatch jobs to the queue:

```php
use GeoMin\Facades\GeoMin;

// Dispatch anomaly detection to queue
$analysis = GeoMin::dispatchProcessingJob($path, 'anomaly_detection', [
    'algorithm' => 'isolation_forest',
    'contamination' => 0.01,
]);

// Check status later
$status = $analysis->status;
```

## Available Spectral Indices

| Index | Formula | Description |
|-------|---------|-------------|
| NDVI | (nir - red) / (nir + red) | Vegetation health |
| NDWI | (green - nir) / (green + nir) | Water content |
| NDMI | (nir - swir1) / (nir + swir1) | Moisture index |
| Iron Oxide | red / blue | Iron oxide minerals |
| Clay | swir1 / swir2 | Clay minerals |
| Ferrous | (nir - swir1) / (nir + swir1) | Ferrous iron |

## Available Reference Minerals

- Kaolinite, Alunite, Jarosite (Clay/Sulfate)
- Hematite, Goethite (Iron Oxide)
- Sericite, Chlorite (Phyllosilicate)
- Calcite, Dolomite (Carbonate)
- Muscovite, Biotite (Mica)
- Quartz, Feldspar

## STAC Endpoints

The package supports multiple STAC providers:

| Name | Endpoint | Collections |
|------|----------|-------------|
| AWS | earth-search.aws.element84.com/v1 | Sentinel-2, Landsat |
| Planetary Computer | planetarycomputer.microsoft.com/api/stac/v1 | Sentinel-2, Landsat, MODIS |
| Element84 | api.stac.terrascope.be/v1 | Sentinel-2 |
| USGS | landsatlook.usgs.gov/stac | Landsat |

## Events

GeoMin dispatches events during analysis lifecycle:

```php
use GeoMin\Events\AnalysisCompleted;
use GeoMin\Events\AnalysisFailed;
use GeoMin\Events\AnalysisStarted;

// Listen for events
Event::listen(function (AnalysisStarted $event) {
    Log::info("Analysis {$event->getAnalysisId()} started");
});

Event::listen(function (AnalysisCompleted $event) {
    Log::info("Analysis {$event->getAnalysisId()} completed");
});

Event::listen(function (AnalysisFailed $event) {
    Log::error("Analysis {$event->getAnalysisId()} failed: {$event->error}");
});
```

## Exception Handling

```php
use GeoMin\Exceptions\STACException;
use GeoMin\Exceptions\AnalysisException;

try {
    $results = GeoMin::stac()->search($options);
} catch (STACException $e) {
    Log::error('STAC error', ['message' => $e->getFullMessage()]);
}

try {
    $result = GeoMin::detectAnomalies($data, $algorithm);
} catch (AnalysisException $e) {
    Log::error('Analysis error', ['context' => $e->getContext()]);
}
```

## Testing

```bash
# Run tests
./vendor/bin/phpunit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

## Performance Considerations

1. **Memory Usage**: Large images can consume significant memory. Consider:
   - Increasing memory_limit in php.ini
   - Using chunked processing for very large images
   - Dispatching to queue for background processing

2. **Queue Configuration**: For production:
   ```php
   'queue' => [
       'connection' => 'redis',
       'queue' => 'geomin',
       'timeout' => 3600,
       'max_retries' => 3,
   ],
   ```

3. **Caching**: STAC search results are cached by default. Adjust cache TTL based on your needs.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email security@example.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) file for more information.

## Author

**Kazashim Kuzasuwat**

- [GitHub](https://github.com/kazashim)
- [Email](mailto:kazashim@example.com)

## Links

- [Package Repository](https://github.com/kazashim/GeoMin-Laravel)
- [Issue Tracker](https://github.com/kazashim/GeoMin-Laravel/issues)
- [Python Version](https://github.com/kazashim/GeoMin)

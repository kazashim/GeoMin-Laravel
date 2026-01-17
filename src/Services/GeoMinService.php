<?php

namespace GeoMin\Services;

use GeoMin\Algorithms\Anomaly\IsolationForestDetector;
use GeoMin\Algorithms\Anomaly\RXDetector;
use GeoMin\Algorithms\Anomaly\LocalOutlierFactorDetector;
use GeoMin\Algorithms\CloudMasking\CloudMasker;
use GeoMin\Algorithms\Mineralogy\AdvancedMineralogy;
use GeoMin\Algorithms\Spectral\SpectralCalculator;
use GeoMin\Clients\STACClient;
use GeoMin\Jobs\ProcessSatelliteImage;
use GeoMin\Models\SpatialAnalysis;
use Illuminate\Support\Facades\Queue;

/**
 * GeoMin Service
 * 
 * Main service class that provides a unified interface to all GeoMin
 * functionality including satellite data access, anomaly detection,
 * and mineral mapping.
 * 
 * This class acts as a facade coordinator, delegating to specialized
 * services while providing a simple API for common operations.
 * 
 * @author Kazashim Kuzasuwat
 */
class GeoMinService
{
    /**
     * STAC Client instance
     */
    protected STACClient $stacClient;

    /**
     * Cloud Masker instance
     */
    protected CloudMasker $cloudMasker;

    /**
     * Spectral Calculator instance
     */
    protected SpectralCalculator $spectralCalculator;

    /**
     * Advanced Mineralogy instance
     */
    protected AdvancedMineralogy $mineralogy;

    /**
     * Anomaly Detector instances
     */
    protected IsolationForestDetector $isolationForest;
    protected RXDetector $rxDetector;
    protected LocalOutlierFactorDetector $lofDetector;

    /**
     * Create a new GeoMin service instance.
     */
    public function __construct(
        STACClient $stacClient,
        CloudMasker $cloudMasker,
        SpectralCalculator $spectralCalculator,
        AdvancedMineralogy $mineralogy,
        IsolationForestDetector $isolationForest,
        RXDetector $rxDetector,
        LocalOutlierFactorDetector $lofDetector
    ) {
        $this->stacClient = $stacClient;
        $this->cloudMasker = $cloudMasker;
        $this->spectralCalculator = $spectralCalculator;
        $this->mineralogy = $mineralogy;
        $this->isolationForest = $isolationForest;
        $this->rxDetector = $rxDetector;
        $this->lofDetector = $lofDetector;
    }

    /**
     * Get the STAC Client for satellite data access.
     */
    public function stac(): STACClient
    {
        return $this->stacClient;
    }

    /**
     * Get the Spectral Calculator for band math operations.
     */
    public function spectral(): SpectralCalculator
    {
        return $this->spectralCalculator;
    }

    /**
     * Get the Advanced Mineralogy analyzer.
     */
    public function mineralogy(): AdvancedMineralogy
    {
        return $this->mineralogy;
    }

    /**
     * Get the Isolation Forest anomaly detector.
     */
    public function isolationForest(): IsolationForestDetector
    {
        return $this->isolationForest;
    }

    /**
     * Get the RX anomaly detector.
     */
    public function rxDetector(): RXDetector
    {
        return $this->rxDetector;
    }

    /**
     * Get the Local Outlier Factor detector.
     */
    public function lofDetector(): LocalOutlierFactorDetector
    {
        return $this->lofDetector;
    }

    /**
     * Get the cloud masker.
     */
    public function cloudMasker(): CloudMasker
    {
        return $this->cloudMasker;
    }

    /**
     * Search for satellite imagery using STAC.
     *
     * @param array $params Search parameters
     * @return \GeoMin\Clients\SearchResult[]
     */
    public function searchSatelliteData(array $params): array
    {
        return $this->stacClient->search($params);
    }

    /**
     * Calculate a spectral index from image data.
     *
     * @param array $bands Array of band data or file paths
     * @param string $index Index name (e.g., 'NDVI', 'NDWI')
     * @return array Result array with index values and metadata
     */
    public function calculateIndex(array $bands, string $index): array
    {
        return $this->spectralCalculator->calculateIndex($bands, $index);
    }

    /**
     * Detect anomalies in satellite imagery.
     *
     * @param array|string $data Image data array or file path
     * @param string $algorithm Algorithm to use ('isolation_forest', 'rx', 'lof')
     * @param array $options Algorithm-specific options
     * @return array Detection results with anomaly scores and masks
     */
    public function detectAnomalies($data, string $algorithm = 'isolation_forest', array $options = []): array
    {
        switch ($algorithm) {
            case 'isolation_forest':
                return $this->isolationForest->detect($data, $options);
            case 'rx':
                return $this->rxDetector->detect($data, $options);
            case 'lof':
                return $this->lofDetector->detect($data, $options);
            default:
                throw new \InvalidArgumentException("Unknown anomaly detection algorithm: {$algorithm}");
        }
    }

    /**
     * Apply cloud masking to satellite imagery.
     *
     * @param array|string $data Image data or file path
     * @param string $algorithm Algorithm to use
     * @param array $options Algorithm options
     * @return array Mask result with cloud mask and statistics
     */
    public function maskClouds($data, string $algorithm = 'sentinel2', array $options = []): array
    {
        return $this->cloudMasker->mask($data, $algorithm, $options);
    }

    /**
     * Perform Crosta PCA for alteration mapping.
     *
     * @param array|string $data Image data or file path
     * @param string $targetMineral Target mineral type ('hydroxyl', 'iron', 'silica')
     * @param int $nComponents Number of PCA components
     * @return array PCA results with components and mineral maps
     */
    public function crostaPCA($data, string $targetMineral = 'hydroxyl', int $nComponents = 4): array
    {
        return $this->mineralogy->crostaPCA($data, $targetMineral, $nComponents);
    }

    /**
     * Calculate Spectral Angle Mapper matches.
     *
     * @param array|string $data Image data or file path
     * @param array $referenceSpectrum Reference mineral spectrum
     * @param float $threshold Matching threshold in radians
     * @return array SAM results with similarity map
     */
    public function spectralAngleMapper($data, array $referenceSpectrum, float $threshold = 0.1): array
    {
        return $this->mineralogy->spectralAngleMapper($data, $referenceSpectrum, $threshold);
    }

    /**
     * Perform linear spectral unmixing.
     *
     * @param array|string $data Image data or file path
     * @param array $endmembers Endmember spectra dictionary
     * @return array Unmixing results with abundance maps
     */
    public function spectralUnmixing($data, array $endmembers): array
    {
        return $this->mineralogy->unmix($data, $endmembers);
    }

    /**
     * Dispatch a satellite image processing job to the queue.
     *
     * @param string $filePath Path to the satellite image
     * @param string $jobType Type of processing job
     * @param array $options Processing options
     * @return SpatialAnalysis The created analysis model
     */
    public function dispatchProcessingJob(string $filePath, string $jobType, array $options = []): SpatialAnalysis
    {
        // Create analysis record
        $analysis = SpatialAnalysis::create([
            'type' => $jobType,
            'status' => 'pending',
            'parameters' => $options,
            'file_path' => $filePath,
        ]);

        // Dispatch job
        Queue::connection(config('geomin.queue.connection', 'redis'))
            ->push(new ProcessSatelliteImage($analysis, $jobType, $options));

        return $analysis;
    }

    /**
     * Get a reference spectrum for a mineral.
     *
     * @param string $mineral Mineral name
     * @param string $source Spectral library source
     * @return array Reference spectrum values
     */
    public function getReferenceSpectrum(string $mineral, string $source = 'usgs'): array
    {
        return $this->mineralogy->getReferenceSpectrum($mineral, $source);
    }

    /**
     * Get available minerals in the reference library.
     *
     * @return array List of available mineral names
     */
    public function getAvailableMinerals(): array
    {
        return $this->mineralogy->getAvailableMinerals();
    }

    /**
     * Get available spectral indices.
     *
     * @return array List of available index names
     */
    public function getAvailableIndices(): array
    {
        return $this->spectralCalculator->getAvailableIndices();
    }

    /**
     * Run a complete mining exploration workflow.
     *
     * @param array $params Workflow parameters
     * @return array Complete analysis results
     */
    public function runExplorationWorkflow(array $params): array
    {
        $results = [
            'metadata' => [],
            'cloud_mask' => null,
            'anomaly_detection' => null,
            'mineral_mapping' => [],
            'priority_targets' => [],
        ];

        // Step 1: Get satellite data if coordinates provided
        if (isset($params['bbox']) || isset($params['geometry'])) {
            $searchResults = $this->stacClient->search($params);
            $results['metadata']['stac_results'] = count($searchResults);
        }

        // Step 2: Load data
        $data = $params['data'] ?? null;
        if (!$data && isset($params['data_path'])) {
            $data = $this->loadData($params['data_path']);
        }

        if (!$data) {
            throw new \InvalidArgumentException('No data provided for analysis');
        }

        // Step 3: Apply cloud masking
        $results['cloud_mask'] = $this->cloudMasker->mask(
            $data,
            $params['cloud_algorithm'] ?? 'sentinel2'
        );

        // Step 4: Detect anomalies
        $algorithm = $params['anomaly_algorithm'] ?? 'rx';
        $results['anomaly_detection'] = $this->detectAnomalies(
            $data,
            $algorithm,
            $params['anomaly_options'] ?? []
        );

        // Step 5: Mineral mapping if requested
        if (isset($params['target_minerals'])) {
            foreach ($params['target_minerals'] as $mineral) {
                $results['mineral_mapping'][$mineral] = $this->crostaPCA(
                    $data,
                    $mineral,
                    $params['n_components'] ?? 4
                );
            }
        }

        // Step 6: Identify priority targets
        $results['priority_targets'] = $this->identifyPriorityTargets(
            $results['anomaly_detection'],
            $results['mineral_mapping']
        );

        return $results;
    }

    /**
     * Identify priority exploration targets from analysis results.
     *
     * @param array $anomalyResults Anomaly detection results
     * @param array $mineralResults Mineral mapping results
     * @return array Priority targets with coordinates and scores
     */
    protected function identifyPriorityTargets(array $anomalyResults, array $mineralResults): array
    {
        $targets = [];

        // Extract top anomaly locations
        $topAnomalies = $anomalyResults['top_locations'] ?? [];
        
        foreach ($topAnomalies as $location) {
            $target = [
                'coordinates' => $location['coordinates'],
                'anomaly_score' => $location['score'],
                'minerals' => [],
                'priority' => 'MEDIUM',
            ];

            // Check for mineral associations
            foreach ($mineralResults as $mineral => $result) {
                $mineralScore = $this->getMineralScoreAtLocation($result, $location);
                if ($mineralScore > 0.5) {
                    $target['minerals'][$mineral] = $mineralScore;
                }
            }

            // Calculate overall priority
            $target['priority'] = $this->calculatePriority($target);

            $targets[] = $target;
        }

        // Sort by priority
        usort($targets, function ($a, $b) {
            return $this->priorityValue($b['priority']) - $this->priorityValue($a['priority']);
        });

        return $targets;
    }

    /**
     * Get mineral score at a specific location.
     */
    protected function getMineralScoreAtLocation(array $result, array $location): float
    {
        // Implementation depends on result format
        return 0.0;
    }

    /**
     * Calculate priority level from component scores.
     */
    protected function calculatePriority(array $target): string
    {
        $anomalyScore = $target['anomaly_score'] ?? 0;
        $mineralCount = count($target['minerals']);

        if ($anomalyScore > 0.8 && $mineralCount >= 2) {
            return 'HIGH';
        } elseif ($anomalyScore > 0.6 && $mineralCount >= 1) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * Convert priority string to numeric value.
     */
    protected function priorityValue(string $priority): int
    {
        return match ($priority) {
            'HIGH' => 3,
            'MEDIUM' => 2,
            'LOW' => 1,
            default => 0,
        };
    }

    /**
     * Load data from a file path.
     *
     * @param string $filePath Path to the data file
     * @return array Loaded data array
     */
    protected function loadData(string $filePath): array
    {
        // Determine file type and load accordingly
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'tif', 'tiff' => $this->loadGeotiff($filePath),
            'nc' => $this->loadNetCDF($filePath),
            'json' => $this->loadJSON($filePath),
            default => throw new \InvalidArgumentException("Unsupported file format: {$extension}"),
        };
    }

    /**
     * Load GeoTIFF data.
     */
    protected function loadGeotiff(string $filePath): array
    {
        // Use GDAL or PHP GD extension
        // This is a placeholder - actual implementation depends on available libraries
        return [];
    }

    /**
     * Load NetCDF data.
     */
    protected function loadNetCDF(string $filePath): array
    {
        // Use a NetCDF library for PHP
        return [];
    }

    /**
     * Load JSON data.
     */
    protected function loadJSON(string $filePath): array
    {
        return json_decode(file_get_contents($filePath), true);
    }
}

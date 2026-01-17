<?php

namespace GeoMin\Algorithms\Anomaly;

use GeoMin\Algorithms\BaseAlgorithm;
use Rubix\ML\AnomalyDetectors\IsolationForest as RubixIsolationForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Extractors\Extractor;
use Rubix\ML\Extractors\CSV;
use Rubix\ML\Persisters\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Isolation Forest Anomaly Detector
 * 
 * Implements the Isolation Forest algorithm for detecting spectral anomalies
 * in satellite imagery that may indicate mineral deposits or mining activity.
 * 
 * The algorithm isolates observations by randomly selecting a feature and
 * then randomly selecting a split value between the max and min of the feature.
 * Anomalies are easier to isolate and thus have shorter path lengths.
 * 
 * @author Kazashim Kuzasuwat
 */
class IsolationForestDetector extends BaseAlgorithm
{
    /**
     * Number of trees in the forest
     */
    protected int $trees;

    /**
     * Contamination rate (expected proportion of anomalies)
     */
    protected float $contamination;

    /**
     * Maximum number of samples to use
     */
    protected mixed $maxSamples;

    /**
     * Create a new Isolation Forest detector.
     *
     * @param int $trees Number of trees (default: 100)
     * @param float $contamination Expected anomaly proportion (default: 0.01)
     * @param int|null $maxSamples Maximum samples or 'auto'
     */
    public function __construct(
        int $trees = 100,
        float $contamination = 0.01,
        ?int $maxSamples = null
    ) {
        $this->trees = $trees;
        $this->contamination = $contamination;
        $this->maxSamples = $maxSamples ?? 'auto';
    }

    /**
     * Detect anomalies in the provided data.
     *
     * @param array|string $data Image data array or file path
     * @param array $options Detection options
     * @return array Result with scores, labels, and mask
     */
    public function detect($data, array $options = []): array
    {
        $options = array_merge([
            'bands' => null,
            'contamination' => $this->contamination,
            'threshold' => null,
            'save_model' => false,
            'model_path' => null,
        ], $options);

        // Load and prepare data
        $imageData = $this->loadData($data);
        $pixelData = $this->preparePixelData($imageData, $options['bands']);

        if (empty($pixelData)) {
            throw new \InvalidArgumentException('No valid pixel data found for analysis');
        }

        Log::info('Starting Isolation Forest anomaly detection', [
            'pixels' => count($pixelData),
            'bands' => count($pixelData[0]),
            'trees' => $this->trees,
        ]);

        // Build the model
        $model = $this->buildModel($options);

        // Train and predict
        try {
            $labels = $model->predict($pixelData);
            $scores = $model->score($pixelData);

            // Convert scores (Rubix returns -1 for anomaly, 1 for normal)
            $anomalyScores = array_map(function ($label, $score) {
                // Convert to 0-1 scale where higher = more anomalous
                return $label === -1 ? 1 - ($score + 1) / 2 : (1 - $score) / 2;
            }, $labels, $scores);

            // Create anomaly mask
            $anomalyMask = array_map(fn($l) => $l === -1, $labels);

            // Calculate statistics
            $nAnomalies = array_sum($anomalyMask);
            $statistics = [
                'method' => 'isolation_forest',
                'trees' => $this->trees,
                'contamination' => $options['contamination'],
                'total_pixels' => count($labels),
                'anomaly_pixels' => $nAnomalies,
                'anomaly_percentage' => ($nAnomalies / count($labels)) * 100,
            ];

            // Get top anomaly locations
            $topLocations = $this->getTopAnomalies($imageData, $anomalyScores, $options['top_n'] ?? 100);

            // Save model if requested
            if ($options['save_model']) {
                $this->saveModel($model, $options['model_path']);
            }

            return [
                'anomaly_scores' => $this->reshapeToImage($anomalyScores, $imageData),
                'anomaly_mask' => $this->reshapeToImage($anomalyMask, $imageData),
                'labels' => $this->reshapeToImage($labels, $imageData),
                'statistics' => $statistics,
                'top_locations' => $topLocations,
            ];

        } catch (\Exception $e) {
            Log::error('Isolation Forest detection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException("Anomaly detection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Build the Isolation Forest model.
     */
    protected function buildModel(array $options): RubixIsolationForest
    {
        return new RubixIsolationForest([
            'trees' => $this->trees,
            'contamination' => $options['contamination'],
            'max_samples' => $this->maxSamples,
        ]);
    }

    /**
     * Prepare pixel data from image.
     *
     * @param array $imageData Image data array
     * @param array|null $bands Bands to use
     * @return array Pixel feature vectors
     */
    protected function preparePixelData(array $imageData, ?array $bands): array
    {
        $pixels = [];
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $nBands = count($imageData[0][0] ?? $imageData[0] ?? []);

        // Determine band indices to use
        $bandIndices = range(0, $nBands - 1);
        if ($bands !== null) {
            $bandIndices = [];
            foreach ($bands as $band) {
                if (is_numeric($band)) {
                    $bandIndices[] = (int) $band;
                }
            }
        }

        // Extract pixel vectors
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = [];
                foreach ($bandIndices as $idx) {
                    $value = $imageData[$y][$x][$idx] ?? 0;
                    if (is_numeric($value) && is_finite($value)) {
                        $pixel[] = (float) $value;
                    }
                }
                if (count($pixel) === count($bandIndices)) {
                    $pixels[] = $pixel;
                }
            }
        }

        return $pixels;
    }

    /**
     * Get top N anomaly locations.
     */
    protected function getTopAnomalies(array $imageData, array $scores, int $n): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        
        // Create indexed array of scores
        $indexedScores = [];
        $idx = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($idx < count($scores)) {
                    $indexedScores[] = [
                        'coordinates' => ['x' => $x, 'y' => $y],
                        'score' => $scores[$idx],
                        'row' => $y,
                        'col' => $x,
                    ];
                }
                $idx++;
            }
        }

        // Sort by score descending
        usort($indexedScores, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($indexedScores, 0, $n);
    }

    /**
     * Save trained model to disk.
     */
    protected function saveModel($model, ?string $path): void
    {
        $path = $path ?? storage_path('geomin/models/isolation_forest_' . time() . '.json');
        
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $persister = new Filesystem($path);
        $persister->save($model);
        
        Log::info('Isolation Forest model saved', ['path' => $path]);
    }

    /**
     * Get algorithm name.
     */
    public function getName(): string
    {
        return 'Isolation Forest';
    }

    /**
     * Get default parameters.
     */
    public function getDefaultParameters(): array
    {
        return [
            'trees' => $this->trees,
            'contamination' => $this->contamination,
            'max_samples' => $this->maxSamples,
        ];
    }
}

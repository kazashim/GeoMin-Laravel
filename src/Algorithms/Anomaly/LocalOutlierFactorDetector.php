<?php

namespace GeoMin\Algorithms\Anomaly;

use GeoMin\Algorithms\BaseAlgorithm;
use Rubix\ML\AnomalyDetectors\LocalOutlierFactor as RubixLOF;
use Illuminate\Support\Facades\Log;

/**
 * Local Outlier Factor Detector
 * 
 * Implements the Local Outlier Factor (LOF) algorithm for detecting
 * density-based local anomalies in spectral data.
 * 
 * Unlike global methods like RX, LOF identifies anomalies that deviate
 * from their local neighborhood, making it effective for detecting
 * anomalies in heterogeneous landscapes.
 * 
 * @author Kazashim Kuzasuwat
 */
class LocalOutlierFactorDetector extends BaseAlgorithm
{
    /**
     * Number of neighbors to consider
     */
    protected int $neighbors;

    /**
     * Contamination rate
     */
    protected float $contamination;

    /**
     * Whether to use novelty detection mode
     */
    protected bool $novelty;

    /**
     * Create a new LOF detector.
     *
     * @param int $neighbors Number of neighbors (default: 20)
     * @param float $contamination Expected anomaly proportion (default: 0.01)
     * @param bool $novelty Use novelty detection mode
     */
    public function __construct(
        int $neighbors = 20,
        float $contamination = 0.01,
        bool $novelty = false
    ) {
        $this->neighbors = $neighbors;
        $this->contamination = $contamination;
        $this->novelty = $novelty;
    }

    /**
     * Detect anomalies using LOF algorithm.
     *
     * @param array|string $data Image data array or file path
     * @param array $options Detection options
     * @return array Result with scores, labels, and mask
     */
    public function detect($data, array $options = []): array
    {
        $options = array_merge([
            'bands' => null,
            'neighbors' => $this->neighbors,
            'contamination' => $this->contamination,
        ], $options);

        // Load and prepare data
        $imageData = $this->loadData($data);
        $pixelData = $this->preparePixelData($imageData, $options['bands']);

        if (empty($pixelData)) {
            throw new \InvalidArgumentException('No valid pixel data found for analysis');
        }

        Log::info('Starting Local Outlier Factor detection', [
            'pixels' => count($pixelData),
            'bands' => count($pixelData[0] ?? []),
            'neighbors' => $options['neighbors'],
        ]);

        // Build LOF model using RubixML
        $model = new RubixLOF([
            'k' => $options['neighbors'],
            'contamination' => $options['contamination'],
            'novelty' => $this->novelty,
        ]);

        try {
            // Train on data
            $model->train($pixelData);

            // Predict
            $labels = $model->predict($pixelData);
            $scores = $model->score($pixelData);

            // Convert scores (LOF: higher = more anomalous)
            $anomalyScores = array_map(function ($label, $score) {
                return $label === -1 ? min(1.0, abs($score)) : 0.0;
            }, $labels, $scores);

            // Create anomaly mask
            $anomalyMask = array_map(fn($l) => $l === -1, $labels);

            // Statistics
            $nAnomalies = array_sum($anomalyMask);
            $statistics = [
                'method' => 'local_outlier_factor',
                'neighbors' => $options['neighbors'],
                'contamination' => $options['contamination'],
                'total_pixels' => count($labels),
                'anomaly_pixels' => $nAnomalies,
                'anomaly_percentage' => ($nAnomalies / count($labels)) * 100,
            ];

            // Top locations
            $topLocations = $this->getTopAnomalies($imageData, $anomalyScores);

            return [
                'anomaly_scores' => $this->reshapeToImage($anomalyScores, $imageData),
                'anomaly_mask' => $this->reshapeToImage($anomalyMask, $imageData),
                'labels' => $this->reshapeToImage($labels, $imageData),
                'statistics' => $statistics,
                'top_locations' => $topLocations,
            ];

        } catch (\Exception $e) {
            Log::error('LOF detection failed', ['error' => $e->getMessage()]);
            
            // Fallback to simple distance-based LOF implementation
            return $this->fallbackLOF($pixelData, $imageData, $options);
        }
    }

    /**
     * Fallback LOF implementation using pure PHP.
     */
    protected function fallbackLOF(array $pixelData, array $imageData, array $options): array
    {
        $k = $options['neighbors'];
        $nPixels = count($pixelData);
        $lofScores = [];

        Log::warning('Using pure PHP LOF implementation');

        // Calculate k-distance for each point
        $kDistances = [];
        for ($i = 0; $i < $nPixels; $i++) {
            $distances = [];
            for ($j = 0; $j < $nPixels; $j++) {
                if ($i !== $j) {
                    $distances[] = [
                        'index' => $j,
                        'distance' => $this->euclideanDistance($pixelData[$i], $pixelData[$j]),
                    ];
                }
            }
            usort($distances, fn($a, $b) => $a['distance'] <=> $b['distance']);
            $kDistances[$i] = [
                'distances' => $distances,
                'k_distance' => $distances[$k - 1]['distance'] ?? 0,
            ];
        }

        // Calculate reachability distance
        $reachabilityDistances = [];
        for ($i = 0; $i < $nPixels; $i++) {
            $reachabilityDistances[$i] = [];
            for ($j = 0; $j < min($k, count($kDistances[$i]['distances'])); $j++) {
                $neighborIdx = $kDistances[$i]['distances'][$j]['index'];
                $reachabilityDistances[$i][$j] = max(
                    $kDistances[$i]['distances'][$j]['distance'],
                    $kDistances[$neighborIdx]['k_distance']
                );
            }
        }

        // Calculate local reachability density
        $lrd = [];
        for ($i = 0; $i < $nPixels; $i++) {
            $sum = array_sum($reachabilityDistances[$i] ?? []);
            $count = count($reachabilityDistances[$i] ?? []);
            $lrd[$i] = $count > 0 ? $count / ($sum + 1e-10) : 0;
        }

        // Calculate LOF scores
        for ($i = 0; $i < $nPixels; $i++) {
            $neighborLRDs = [];
            for ($j = 0; $j < min($k, count($kDistances[$i]['distances'])); $j++) {
                $neighborIdx = $kDistances[$i]['distances'][$j]['index'];
                $neighborLRDs[] = $lrd[$neighborIdx];
            }
            
            $sumNeighborLRD = array_sum($neighborLRDs);
            $count = count($neighborLRDs);
            
            $lofScores[$i] = $count > 0 && $lrd[$i] > 0
                ? ($sumNeighborLRD / $count) / $lrd[$i]
                : 1.0;
        }

        // Normalize scores
        $maxScore = max($lofScores) ?: 1;
        $lofScores = array_map(fn($s) => min(1.0, $s / $maxScore), $lofScores);

        // Determine threshold
        $threshold = $this->calculateThreshold($lofScores, $options['contamination']);
        $labels = array_map(fn($s) => $s > $threshold ? -1 : 1, $lofScores);
        $anomalyMask = array_map(fn($l) => $l === -1, $labels);

        $nAnomalies = array_sum($anomalyMask);

        $statistics = [
            'method' => 'local_outlier_factor',
            'implementation' => 'pure_php',
            'neighbors' => $k,
            'contamination' => $options['contamination'],
            'total_pixels' => $nPixels,
            'anomaly_pixels' => $nAnomalies,
            'anomaly_percentage' => ($nAnomalies / $nPixels) * 100,
        ];

        $topLocations = $this->getTopAnomalies($imageData, $lofScores);

        return [
            'anomaly_scores' => $this->reshapeToImage($lofScores, $imageData),
            'anomaly_mask' => $this->reshapeToImage($anomalyMask, $imageData),
            'labels' => $this->reshapeToImage($labels, $imageData),
            'statistics' => $statistics,
            'top_locations' => $topLocations,
        ];
    }

    /**
     * Calculate Euclidean distance between two vectors.
     */
    protected function euclideanDistance(array $a, array $b): float
    {
        $sum = 0;
        for ($i = 0; $i < min(count($a), count($b)); $i++) {
            $diff = $a[$i] - $b[$i];
            $sum += $diff * $diff;
        }
        return sqrt($sum);
    }

    /**
     * Calculate threshold from scores.
     */
    protected function calculateThreshold(array $scores, float $contamination): float
    {
        sort($scores);
        $idx = intval((1 - $contamination) * (count($scores) - 1));
        return $scores[$idx] ?? 0;
    }

    /**
     * Get top anomaly locations.
     */
    protected function getTopAnomalies(array $imageData, array $scores, int $n = 100): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);

        $indexedScores = [];
        $idx = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($idx < count($scores)) {
                    $indexedScores[] = [
                        'coordinates' => ['x' => $x, 'y' => $y],
                        'score' => $scores[$idx],
                    ];
                }
                $idx++;
            }
        }

        usort($indexedScores, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($indexedScores, 0, $n);
    }

    /**
     * Get algorithm name.
     */
    public function getName(): string
    {
        return 'Local Outlier Factor';
    }

    /**
     * Get default parameters.
     */
    public function getDefaultParameters(): array
    {
        return [
            'neighbors' => $this->neighbors,
            'contamination' => $this->contamination,
            'novelty' => $this->novelty,
        ];
    }
}

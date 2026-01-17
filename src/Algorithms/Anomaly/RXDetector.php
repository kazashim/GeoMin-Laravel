<?php

namespace GeoMin\Algorithms\Anomaly;

use GeoMin\Algorithms\BaseAlgorithm;
use Illuminate\Support\Facades\Log;

/**
 * RX (Reed-Xiaoli) Anomaly Detector
 * 
 * Implements the RX (Reed-Xiaoli) anomaly detector, which is a standard
 * algorithm in remote sensing for detecting spectral anomalies.
 * 
 * The RX detector computes the Mahalanobis distance of each pixel from
 * the global mean, identifying anomalies as pixels with high deviation.
 * This algorithm is particularly effective for identifying subtle spectral
 * anomalies that may indicate mineral deposits.
 * 
 * @author Kazashim Kuzasuwat
 */
class RXDetector extends BaseAlgorithm
{
    /**
     * Threshold percentile for anomaly detection (0-1)
     */
    protected float $threshold;

    /**
     * Whether to use robust statistics (median instead of mean)
     */
    protected bool $useRobust;

    /**
     * Window size for local RX (null = global RX)
     */
    protected ?int $windowSize;

    /**
     * Create a new RX detector.
     *
     * @param float $threshold Percentile threshold (default: 0.99)
     * @param bool $useRobust Use robust statistics
     * @param int|null $windowSize Local window size (null = global)
     */
    public function __construct(
        float $threshold = 0.99,
        bool $useRobust = false,
        ?int $windowSize = null
    ) {
        $this->threshold = $threshold;
        $this->useRobust = $useRobust;
        $this->windowSize = $windowSize;
    }

    /**
     * Detect anomalies using the RX algorithm.
     *
     * @param array|string $data Image data array or file path
     * @param array $options Detection options
     * @return array Result with scores, labels, and mask
     */
    public function detect($data, array $options = []): array
    {
        $options = array_merge([
            'bands' => null,
            'threshold' => $this->threshold,
            'use_robust' => $this->useRobust,
            'window_size' => $this->windowSize,
        ], $options);

        // Load and prepare data
        $imageData = $this->loadData($data);
        $pixelData = $this->preparePixelData($imageData, $options['bands']);

        if (empty($pixelData)) {
            throw new \InvalidArgumentException('No valid pixel data found for analysis');
        }

        Log::info('Starting RX anomaly detection', [
            'pixels' => count($pixelData),
            'bands' => count($pixelData[0] ?? []),
            'threshold' => $options['threshold'],
            'window_size' => $options['window_size'],
        ]);

        // Use global or local RX
        if ($options['window_size'] !== null) {
            $result = $this->localRX($pixelData, $imageData, $options);
        } else {
            $result = $this->globalRX($pixelData, $imageData, $options);
        }

        return $result;
    }

    /**
     * Global RX anomaly detection.
     *
     * Computes Mahalanobis distance from global mean for all pixels.
     */
    protected function globalRX(array $pixelData, array $imageData, array $options): array
    {
        $nPixels = count($pixelData);
        $nBands = count($pixelData[0]);

        // Calculate mean vector
        $mean = array_fill(0, $nBands, 0.0);
        foreach ($pixelData as $pixel) {
            for ($i = 0; $i < $nBands; $i++) {
                $mean[$i] += $pixel[$i];
            }
        }
        for ($i = 0; $i < $nBands; $i++) {
            $mean[$i] /= $nPixels;
        }

        // Calculate covariance matrix
        $cov = array_fill(0, $nBands, array_fill(0, $nBands, 0.0));
        foreach ($pixelData as $pixel) {
            $diff = [];
            for ($i = 0; $i < $nBands; $i++) {
                $diff[$i] = $pixel[$i] - $mean[$i];
            }
            for ($i = 0; $i < $nBands; $i++) {
                for ($j = 0; $j < $nBands; $j++) {
                    $cov[$i][$j] += $diff[$i] * $diff[$j];
                }
            }
        }
        for ($i = 0; $i < $nBands; $i++) {
            for ($j = 0; $j < $nBands; $j++) {
                $cov[$i][$j] /= ($nPixels - 1);
            }
        }

        // Regularize covariance matrix if needed
        $cov = $this->regularizeCovariance($cov);

        // Calculate inverse covariance
        $covInv = $this->matrixInverse($cov);

        // Calculate RX scores (Mahalanobis distances)
        $rxScores = [];
        foreach ($pixelData as $pixel) {
            $diff = [];
            for ($i = 0; $i < $nBands; $i++) {
                $diff[$i] = $pixel[$i] - $mean[$i];
            }
            
            // Mahalanobis distance: sqrt(diff' * covInv * diff)
            $md = 0;
            for ($i = 0; $i < $nBands; $i++) {
                for ($j = 0; $j < $nBands; $j++) {
                    $md += $diff[$i] * $covInv[$i][$j] * $diff[$j];
                }
            }
            $rxScores[] = sqrt(max(0, $md));
        }

        // Normalize scores to 0-1
        $maxScore = max($rxScores);
        if ($maxScore > 0) {
            $rxScores = array_map(fn($s) => $s / $maxScore, $rxScores);
        }

        // Determine threshold and labels
        $thresholdValue = $this->calculateThreshold($rxScores, $options['threshold']);
        $anomalyLabels = array_map(fn($s) => $s > $thresholdValue ? -1 : 1, $rxScores);

        // Statistics
        $nAnomalies = array_sum($anomalyLabels === -1);
        $statistics = [
            'method' => 'rx_anomaly_detector',
            'type' => 'global',
            'threshold' => $thresholdValue,
            'total_pixels' => count($rxScores),
            'anomaly_pixels' => $nAnomalies,
            'anomaly_percentage' => ($nAnomalies / count($rxScores)) * 100,
            'mean_score' => array_sum($rxScores) / count($rxScores),
        ];

        // Top locations
        $topLocations = $this->getTopAnomalies($imageData, $rxScores);

        return [
            'anomaly_scores' => $this->reshapeToImage($rxScores, $imageData),
            'anomaly_mask' => $this->reshapeToImage($anomalyLabels === -1, $imageData),
            'labels' => $this->reshapeToImage($anomalyLabels, $imageData),
            'statistics' => $statistics,
            'top_locations' => $topLocations,
            'mean_vector' => $mean,
            'covariance_matrix' => $cov,
        ];
    }

    /**
     * Local RX anomaly detection using sliding window.
     *
     * Computes RX score for each pixel using local neighborhood statistics.
     */
    protected function localRX(array $pixelData, array $imageData, array $options): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $windowSize = $options['window_size'];
        $halfWindow = intdiv($windowSize, 2);

        $rxScores = array_fill(0, $height, array_fill(0, $width, 0.0));

        Log::info('Processing local RX detection', [
            'image_size' => "{$width}x{$height}",
            'window_size' => $windowSize,
        ]);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // Get local window
                $localData = [];
                for ($wy = max(0, $y - $halfWindow); $wy < min($height, $y + $halfWindow + 1); $wy++) {
                    for ($wx = max(0, $x - $halfWindow); $wx < min($width, $x + $halfWindow + 1); $wx++) {
                        if ($wy === $y && $wx === $x) continue; // Skip center pixel
                        if (isset($pixelData[$wy * $width + $wx])) {
                            $localData[] = $pixelData[$wy * $width + $wx];
                        }
                    }
                }

                if (count($localData) < 2) {
                    continue;
                }

                // Calculate local statistics
                $nBands = count($localData[0]);
                $localMean = array_fill(0, $nBands, 0.0);
                foreach ($localData as $pixel) {
                    for ($i = 0; $i < $nBands; $i++) {
                        $localMean[$i] += $pixel[$i];
                    }
                }
                for ($i = 0; $i < $nBands; $i++) {
                    $localMean[$i] /= count($localData);
                }

                // Calculate local covariance
                $localCov = array_fill(0, $nBands, array_fill(0, $nBands, 0.0));
                foreach ($localData as $pixel) {
                    $diff = [];
                    for ($i = 0; $i < $nBands; $i++) {
                        $diff[$i] = $pixel[$i] - $localMean[$i];
                    }
                    for ($i = 0; $i < $nBands; $i++) {
                        for ($j = 0; $j < $nBands; $j++) {
                            $localCov[$i][$j] += $diff[$i] * $diff[$j];
                        }
                    }
                }
                for ($i = 0; $i < $nBands; $i++) {
                    for ($j = 0; $j < $nBands; $j++) {
                        $localCov[$i][$j] /= (count($localData) - 1);
                    }
                }

                // Regularize and invert
                $localCov = $this->regularizeCovariance($localCov);
                $localCovInv = $this->matrixInverse($localCov);

                // Calculate RX score for center pixel
                $centerPixel = $pixelData[$y * $width + $x] ?? null;
                if ($centerPixel) {
                    $diff = [];
                    for ($i = 0; $i < $nBands; $i++) {
                        $diff[$i] = $centerPixel[$i] - $localMean[$i];
                    }
                    
                    $md = 0;
                    for ($i = 0; $i < $nBands; $i++) {
                        for ($j = 0; $j < $nBands; $j++) {
                            $md += $diff[$i] * $localCovInv[$i][$j] * $diff[$j];
                        }
                    }
                    $rxScores[$y][$x] = sqrt(max(0, $md));
                }
            }
        }

        // Normalize
        $allScores = array_merge(...array_map(fn($row) => array_values($row), $rxScores));
        $maxScore = max($allScores) ?: 1;
        $rxScores = array_map(
            fn($row) => array_map(fn($s) => $s / $maxScore, $row),
            $rxScores
        );

        // Threshold
        $thresholdValue = $this->calculateThreshold($allScores, $options['threshold']);
        $anomalyMask = array_map(
            fn($row) => array_map(fn($s) => $s > $thresholdValue, $row),
            $rxScores
        );

        $nAnomalies = array_sum(...array_map(fn($row) => array_map(fn($m) => $m ? 1 : 0, $row), $anomalyMask));
        $totalPixels = $width * $height;

        $statistics = [
            'method' => 'rx_anomaly_detector',
            'type' => 'local',
            'window_size' => $windowSize,
            'threshold' => $thresholdValue,
            'total_pixels' => $totalPixels,
            'anomaly_pixels' => $nAnomalies,
            'anomaly_percentage' => ($nAnomalies / $totalPixels) * 100,
        ];

        $topLocations = $this->getTopAnomalies($imageData, $allScores);

        return [
            'anomaly_scores' => $rxScores,
            'anomaly_mask' => $anomalyMask,
            'statistics' => $statistics,
            'top_locations' => $topLocations,
        ];
    }

    /**
     * Regularize covariance matrix for numerical stability.
     */
    protected function regularizeCovariance(array $cov): array
    {
        $n = count($cov);
        $trace = 0;
        for ($i = 0; $i < $n; $i++) {
            $trace += $cov[$i][$i];
        }
        $regularization = ($trace / $n) * 0.01;

        for ($i = 0; $i < $n; $i++) {
            $cov[$i][$i] += $regularization;
        }

        return $cov;
    }

    /**
     * Calculate matrix inverse using Gaussian elimination.
     */
    protected function matrixInverse(array $matrix): array
    {
        $n = count($matrix);
        
        // Create augmented matrix [A|I]
        $augmented = [];
        for ($i = 0; $i < $n; $i++) {
            $augmented[$i] = array_merge($matrix[$i], array_fill(0, $n, 0.0));
            $augmented[$i][$n + $i] = 1.0;
        }

        // Gaussian elimination with partial pivoting
        for ($col = 0; $col < $n; $col++) {
            // Find pivot
            $maxRow = $col;
            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($augmented[$row][$col]) > abs($augmented[$maxRow][$col])) {
                    $maxRow = $row;
                }
            }

            // Swap rows
            [$augmented[$col], $augmented[$maxRow]] = [$augmented[$maxRow], $augmented[$col]];

            // Check for singular matrix
            if (abs($augmented[$col][$col]) < 1e-10) {
                continue;
            }

            // Scale pivot row
            $pivot = $augmented[$col][$col];
            for ($j = 0; $j < 2 * $n; $j++) {
                $augmented[$col][$j] /= $pivot;
            }

            // Eliminate column
            for ($row = 0; $row < $n; $row++) {
                if ($row === $col) continue;
                $factor = $augmented[$row][$col];
                for ($j = 0; $j < 2 * $n; $j++) {
                    $augmented[$row][$j] -= $factor * $augmented[$col][$j];
                }
            }
        }

        // Extract inverse
        $inverse = [];
        for ($i = 0; $i < $n; $i++) {
            $inverse[$i] = array_slice($augmented[$i], $n);
        }

        return $inverse;
    }

    /**
     * Calculate threshold from scores.
     */
    protected function calculateThreshold(array $scores, float $percentile): float
    {
        sort($scores);
        $idx = intval($percentile * (count($scores) - 1));
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
        return 'RX Anomaly Detector';
    }

    /**
     * Get default parameters.
     */
    public function getDefaultParameters(): array
    {
        return [
            'threshold' => $this->threshold,
            'use_robust' => $this->useRobust,
            'window_size' => $this->windowSize,
        ];
    }
}

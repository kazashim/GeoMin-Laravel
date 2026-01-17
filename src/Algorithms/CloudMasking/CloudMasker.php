<?php

namespace GeoMin\Algorithms\CloudMasking;

use GeoMin\Algorithms\BaseAlgorithm;
use Illuminate\Support\Facades\Log;

/**
 * Cloud Masker
 * 
 * Implements cloud detection and masking algorithms for satellite imagery.
 * Supports multiple algorithms optimized for different sensors including
 * Sentinel-2 and Landsat.
 * 
 * Features:
 * - Threshold-based cloud detection
 * - Sentinel-2 specific cloud detection with cirrus band
 * - Landsat QA band parsing
 * - Cloud probability estimation
 * 
 * @author Kazashim Kuzasuwat
 */
class CloudMasker extends BaseAlgorithm
{
    /**
     * Sentinel-2 cloud detection thresholds
     */
    public const SENTINEL2_THRESHOLDS = [
        'blue_threshold' => 0.3,
        'nir_threshold' => 0.4,
        'swir_ratio_threshold' => 0.75,
        'cloud_confidence' => 0.4,
        'cirrus_threshold' => 0.01,
        'whiteness_threshold' => 0.15,
    ];

    /**
     * Landsat QA bit masks
     */
    public const LANDSAT_QA_FLAGS = [
        'dilated_cloud' => (1 << 1),
        'cirrus' => (1 << 2),
        'cloud' => (1 << 3),
        'cloud_shadow' => (1 << 4),
    ];

    /**
     * Default algorithm
     */
    protected string $defaultAlgorithm;

    /**
     * Create a new Cloud Masker.
     *
     * @param string $defaultAlgorithm Default masking algorithm
     */
    public function __construct(string $defaultAlgorithm = 'sentinel2')
    {
        $this->defaultAlgorithm = $defaultAlgorithm;
    }

    /**
     * Apply cloud masking to satellite imagery.
     *
     * @param array|string $data Image data array or file path
     * @param string $algorithm Algorithm to use ('threshold', 'sentinel2', 'landsat_qa')
     * @param array $options Algorithm-specific options
     * @return array Mask result with cloud mask and statistics
     */
    public function mask($data, string $algorithm = 'sentinel2', array $options = []): array
    {
        // Load data
        $imageData = $this->loadData($data);
        
        // Get band mappings
        $bandMapping = $this->getBandMapping($imageData, $options['bands'] ?? []);

        Log::info('Applying cloud masking', [
            'algorithm' => $algorithm,
            'image_size' => count($imageData) . 'x' . count($imageData[0] ?? []),
        ]);

        // Apply selected algorithm
        return match ($algorithm) {
            'threshold' => $this->maskThreshold($imageData, $bandMapping, $options),
            'sentinel2' => $this->maskSentinel2($imageData, $bandMapping, $options),
            'landsat_qa' => $this->maskLandsatQA($imageData, $bandMapping, $options),
            default => throw new \InvalidArgumentException("Unknown cloud masking algorithm: {$algorithm}"),
        };
    }

    /**
     * Get band mapping for the data.
     */
    protected function getBandMapping(array $imageData, array $requestedBands): array
    {
        // Default band names based on common conventions
        $defaults = [
            'standard' => [
                'blue' => 0,
                'green' => 1,
                'red' => 2,
                'nir' => 3,
                'swir1' => 4,
                'swir2' => 5,
                'cirrus' => 6,
            ],
            'sentinel2' => [
                'B02' => 0,
                'B03' => 1,
                'B04' => 2,
                'B08' => 3,
                'B11' => 4,
                'B12' => 5,
                'B10' => 6,
            ],
            'landsat' => [
                'blue' => 0,
                'green' => 1,
                'red' => 2,
                'nir' => 3,
                'swir1' => 4,
                'swir2' => 5,
                'qa' => 6,
            ],
        ];

        // Use provided bands or detect
        if (!empty($requestedBands)) {
            return $requestedBands;
        }

        // Detect based on number of bands
        $nBands = count($imageData[0][0] ?? $imageData[0] ?? []);
        
        if ($nBands === 7) {
            return $defaults['sentinel2'];
        } elseif ($nBands >= 6) {
            return $defaults['landsat'];
        }

        return $defaults['standard'];
    }

    /**
     * Threshold-based cloud detection.
     *
     * Uses blue band threshold and NIR/SWIR ratio to detect clouds.
     */
    protected function maskThreshold(array $imageData, array $bandMapping, array $options): array
    {
        $thresholds = array_merge([
            'blue_threshold' => 0.3,
            'nir_threshold' => 0.4,
            'swir_ratio_threshold' => 0.75,
        ], $options['thresholds'] ?? []);

        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $cloudMask = array_fill(0, $height, array_fill(0, $width, false));

        $blueIdx = $bandMapping['blue'] ?? 0;
        $nirIdx = $bandMapping['nir'] ?? 3;
        $swir1Idx = $bandMapping['swir1'] ?? 4;
        $swir2Idx = $bandMapping['swir2'] ?? 5;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixel($imageData, $x, $y);
                
                $blue = $pixel[$blueIdx] ?? 0;
                $nir = $pixel[$nirIdx] ?? 0;
                $swir1 = $pixel[$swir1Idx] ?? 0;
                $swir2 = $pixel[$swir2Idx] ?? 0;

                // Cloud detection criteria
                $isCloud = false;

                // High blue reflectance
                if ($blue > $thresholds['blue_threshold']) {
                    $isCloud = true;
                }

                // Low NIR reflectance
                if ($nir < $thresholds['nir_threshold']) {
                    $isCloud = true;
                }

                // SWIR ratio
                if ($swir2 > 0) {
                    $swirRatio = $swir1 / $swir2;
                    if ($swirRatio > $thresholds['swir_ratio_threshold']) {
                        $isCloud = true;
                    }
                }

                $cloudMask[$y][$x] = $isCloud;
            }
        }

        return $this->formatMaskResult($cloudMask, $imageData, 'threshold', $thresholds);
    }

    /**
     * Sentinel-2 specific cloud detection.
     *
     * Uses band combinations optimized for Sentinel-2 spectral characteristics.
     */
    protected function maskSentinel2(array $imageData, array $bandMapping, array $options): array
    {
        $thresholds = array_merge(self::SENTINEL2_THRESHOLDS, $options['thresholds'] ?? []);

        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $cloudMask = array_fill(0, $height, array_fill(0, $width, false));
        $cloudProbability = array_fill(0, $height, array_fill(0, $width, 0.0));

        $blueIdx = $bandMapping['B02'] ?? $bandMapping['blue'] ?? 0;
        $greenIdx = $bandMapping['B03'] ?? $bandMapping['green'] ?? 1;
        $redIdx = $bandMapping['B04'] ?? $bandMapping['red'] ?? 2;
        $nirIdx = $bandMapping['B08'] ?? $bandMapping['nir'] ?? 3;
        $swir1Idx = $bandMapping['B11'] ?? $bandMapping['swir1'] ?? 4;
        $swir2Idx = $bandMapping['B12'] ?? $bandMapping['swir2'] ?? 5;
        $cirrusIdx = $bandMapping['B10'] ?? $bandMapping['cirrus'] ?? 6;

        // Calculate statistics for adaptive thresholds
        $blueValues = [];
        $cirrusValues = [];
        
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixel($imageData, $x, $y);
                $blueValues[] = $pixel[$blueIdx] ?? 0;
                if (isset($pixel[$cirrusIdx])) {
                    $cirrusValues[] = $pixel[$cirrusIdx];
                }
            }
        }

        $bluePercentile95 = $this->percentile($blueValues, 95);
        $cirrusThreshold = !empty($cirrusValues) 
            ? max($this->percentile($cirrusValues, 95) * 0.5, $thresholds['cirrus_threshold'])
            : $thresholds['cirrus_threshold'];

        // Main cloud detection pass
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixel($imageData, $x, $y);
                
                $blue = $pixel[$blueIdx] ?? 0;
                $green = $pixel[$greenIdx] ?? 0;
                $red = $pixel[$redIdx] ?? 0;
                $nir = $pixel[$nirIdx] ?? 0;
                $swir1 = $pixel[$swir1Idx] ?? 0;
                $swir2 = $pixel[$swir2Idx] ?? 0;
                $cirrus = $pixel[$cirrusIdx] ?? 0;

                $isCloud = false;
                $probability = 0.0;

                // 1. High blue reflectance
                if ($blue > $thresholds['blue_threshold']) {
                    $isCloud = true;
                    $probability += 0.4 * min(1.0, $blue / 0.6);
                }

                // 2. Cirrus detection
                if ($cirrus > $cirrusThreshold) {
                    $isCloud = true;
                    $probability += 0.4 * min(1.0, $cirrus / ($cirrusThreshold * 3));
                }

                // 3. Whiteness (low variance between visible bands)
                $meanVis = ($blue + $green + $red) / 3;
                $stdVis = sqrt(
                    pow($blue - $meanVis, 2) +
                    pow($green - $meanVis, 2) +
                    pow($red - $meanVis, 2)
                ) / sqrt(3);
                
                if ($meanVis > 0.2) {
                    $relativeStd = $stdVis / ($meanVis + 1e-6);
                    if ($relativeStd < $thresholds['whiteness_threshold']) {
                        $isCloud = true;
                        $probability += 0.2 * (1 - $relativeStd / $thresholds['whiteness_threshold']);
                    }
                }

                // 4. SWIR ratio
                if ($swir2 > 0) {
                    $swirRatio = $swir1 / $swir2;
                    if ($swirRatio > $thresholds['swir_ratio_threshold']) {
                        $isCloud = true;
                        $probability += 0.2;
                    }
                }

                // 5. NIR/Red ratio (vegetation has high NIR/Red, clouds don't)
                if ($red > 0.01) {
                    $nirRedRatio = $nir / $red;
                    if ($nirRedRatio < 0.8 && $blue > 0.25) {
                        $isCloud = true;
                        $probability += 0.2;
                    }
                }

                $cloudMask[$y][$x] = $isCloud;
                $cloudProbability[$y][$x] = min(1.0, $probability);
            }
        }

        return $this->formatMaskResult($cloudMask, $imageData, 'sentinel2', $thresholds, $cloudProbability);
    }

    /**
     * Landsat QA band-based cloud detection.
     *
     * Uses the quality assessment band for reliable cloud detection.
     */
    protected function maskLandsatQA(array $imageData, array $bandMapping, array $options): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $cloudMask = array_fill(0, $height, array_fill(0, $width, false));

        $qaIdx = $bandMapping['qa'] ?? $bandMapping['QA'] ?? 6;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixel($imageData, $x, $y);
                $qaValue = (int) ($pixel[$qaIdx] ?? 0);

                $isCloud = false;

                // Check cloud bit (bit 3)
                if (($qaValue & self::LANDSAT_QA_FLAGS['cloud']) !== 0) {
                    $isCloud = true;
                }

                // Check cloud shadow bit (bit 4)
                if (($qaValue & self::LANDSAT_QA_FLAGS['cloud_shadow']) !== 0) {
                    $isCloud = true;
                }

                // Check dilated cloud bit (bit 1)
                if (($qaValue & self::LANDSAT_QA_FLAGS['dilated_cloud']) !== 0) {
                    $isCloud = true;
                }

                // Check cirrus bit (bit 2)
                if (($qaValue & self::LANDSAT_QA_FLAGS['cirrus']) !== 0) {
                    $isCloud = true;
                }

                $cloudMask[$y][$x] = $isCloud;
            }
        }

        return $this->formatMaskResult($cloudMask, $imageData, 'landsat_qa');
    }

    /**
     * Apply cloud mask to data, setting masked pixels to fill value.
     *
     * @param array $imageData Original image data
     * @param array $cloudMask Cloud mask (true = cloud)
     * @param float $fillValue Value to use for masked pixels
     * @return array Masked image data
     */
    public function applyMask(array $imageData, array $cloudMask, float $fillValue = 0.0): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $nBands = count($imageData[0][0] ?? $imageData[0] ?? []);

        $masked = [];

        for ($y = 0; $y < $height; $y++) {
            $maskedRow = [];
            for ($x = 0; $x < $width; $x++) {
                if ($cloudMask[$y][$x]) {
                    if ($nBands > 1) {
                        $maskedRow[] = array_fill(0, $nBands, $fillValue);
                    } else {
                        $maskedRow[] = [$fillValue];
                    }
                } else {
                    $maskedRow[] = $imageData[$y][$x];
                }
            }
            $masked[] = $maskedRow;
        }

        return $masked;
    }

    /**
     * Get clear pixel mask (inverted cloud mask).
     *
     * @param array $cloudMask Cloud mask
     * @return array Clear pixel mask
     */
    public function getClearPixels(array $cloudMask): array
    {
        return array_map(
            fn($row) => array_map(fn($pixel) => !$pixel, $row),
            $cloudMask
        );
    }

    /**
     * Format mask result with statistics.
     */
    protected function formatMaskResult(
        array $cloudMask, 
        array $imageData, 
        string $algorithm,
        array $thresholds = [],
        ?array $probability = null
    ): array {
        $height = count($cloudMask);
        $width = count($cloudMask[0] ?? []);
        $totalPixels = $height * $width;

        $cloudPixels = 0;
        $clearPixels = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($cloudMask[$y][$x]) {
                    $cloudPixels++;
                } else {
                    $clearPixels++;
                }
            }
        }

        $statistics = [
            'total_pixels' => $totalPixels,
            'cloud_pixels' => $cloudPixels,
            'clear_pixels' => $clearPixels,
            'cloud_percentage' => ($cloudPixels / $totalPixels) * 100,
            'clear_percentage' => ($clearPixels / $totalPixels) * 100,
            'algorithm' => $algorithm,
        ];

        if (!empty($thresholds)) {
            $statistics['thresholds'] = $thresholds;
        }

        return [
            'mask' => $cloudMask,
            'probability' => $probability,
            'statistics' => $statistics,
        ];
    }

    /**
     * Get pixel value at coordinates.
     */
    protected function getPixel(array $imageData, int $x, int $y): array
    {
        if (isset($imageData[$y][$x])) {
            $pixel = $imageData[$y][$x];
            return is_array($pixel) ? $pixel : [$pixel];
        }
        return [];
    }

    /**
     * Calculate percentile of values.
     */
    protected function percentile(array $values, float $percentile): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return $values[$lower];
        }

        return $values[$lower] * (1 - $fraction) + $values[$upper] * $fraction;
    }

    /**
     * Get algorithm name.
     */
    public function getName(): string
    {
        return 'Cloud Masker';
    }

    /**
     * Get default parameters.
     */
    public function getDefaultParameters(): array
    {
        return [
            'default_algorithm' => $this->defaultAlgorithm,
        ];
    }
}

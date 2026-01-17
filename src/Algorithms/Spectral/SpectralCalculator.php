<?php

namespace GeoMin\Algorithms\Spectral;

use GeoMin\Algorithms\BaseAlgorithm;
use Illuminate\Support\Facades\Log;

/**
 * Spectral Calculator
 * 
 * Provides band math operations for calculating spectral indices
 * commonly used in mineral exploration and vegetation analysis.
 * 
 * Supported indices:
 * - NDVI (Normalized Difference Vegetation Index)
 * - NDWI (Normalized Difference Water Index)
 * - Iron Oxide Ratio
 * - Clay Ratio
 * - Ferrous Iron Index
 * - And more...
 * 
 * @author Kazashim Kuzasuwat
 */
class SpectralCalculator extends BaseAlgorithm
{
    /**
     * Available spectral indices with formulas and metadata.
     */
    public const INDICES = [
        'ndvi' => [
            'name' => 'Normalized Difference Vegetation Index',
            'formula' => '(nir - red) / (nir + red)',
            'bands' => ['nir', 'red'],
            'range' => [-1, 1],
            'description' => 'Measures vegetation health and density',
        ],
        'ndwi' => [
            'name' => 'Normalized Difference Water Index',
            'formula' => '(green - nir) / (green + nir)',
            'bands' => ['green', 'nir'],
            'range' => [-1, 1],
            'description' => 'Detects water bodies and moisture content',
        ],
        'ndmi' => [
            'name' => 'Normalized Difference Moisture Index',
            'formula' => '(nir - swir1) / (nir + swir1)',
            'bands' => ['nir', 'swir1'],
            'range' => [-1, 1],
            'description' => 'Measures vegetation liquid water content',
        ],
        'iron_oxide' => [
            'name' => 'Iron Oxide Ratio',
            'formula' => 'red / blue',
            'bands' => ['red', 'blue'],
            'range' => [0, 5],
            'description' => 'Highlights iron oxide minerals (hematite, goethite)',
        ],
        'clay' => [
            'name' => 'Clay Ratio',
            'formula' => 'swir1 / swir2',
            'bands' => ['swir1', 'swir2'],
            'range' => [0, 5],
            'description' => 'Detects clay minerals (kaolinite, alunite)',
        ],
        'ferrous' => [
            'name' => 'Ferrous Iron Index',
            'formula' => '(nir - swir1) / (nir + swir1)',
            'bands' => ['nir', 'swir1'],
            'range' => [-1, 1],
            'description' => 'Detects ferrous iron in rocks and soils',
        ],
        'gosi' => [
            'name' => 'GOES Surface/Soil Index',
            'formula' => '(red - nir) / (red + nir)',
            'bands' => ['red', 'nir'],
            'range' => [-1, 1],
            'description' => 'Differentiates soil from vegetation',
        ],
        'ndsi' => [
            'name' => 'Normalized Difference Snow Index',
            'formula' => '(green - swir1) / (green + swir1)',
            'bands' => ['green', 'swir1'],
            'range' => [-1, 1],
            'description' => 'Snow detection and mapping',
        ],
        'mndwi' => [
            'name' => 'Modified NDWI',
            'formula' => '(green - swir1) / (green + swir1)',
            'bands' => ['green', 'swir1'],
            'range' => [-1, 1],
            'description' => 'Enhanced water body detection',
        ],
        'awesh' => [
            'name' => 'Automated Water Exclusion Index',
            'formula' => '(blue + green + red + nir + swir1 + swir2) / 6',
            'bands' => ['blue', 'green', 'red', 'nir', 'swir1', 'swir2'],
            'range' => [0, 1],
            'description' => 'Water exclusion for cloud masking',
        ],
        'swir_ratio' => [
            'name' => 'SWIR Ratio',
            'formula' => 'swir1 / swir2',
            'bands' => ['swir1', 'swir2'],
            'range' => [0, 3],
            'description' => 'General SWIR ratio for material discrimination',
        ],
        'nir_red_ratio' => [
            'name' => 'NIR/Red Ratio',
            'formula' => 'nir / red',
            'bands' => ['nir', 'red'],
            'range' => [0, 10],
            'description' => 'Vegetation stress indicator',
        ],
    ];

    /**
     * Band name mappings for different sensors.
     */
    public const BAND_MAPPINGS = [
        'standard' => [
            'blue' => 0,
            'green' => 1,
            'red' => 2,
            'nir' => 3,
            'swir1' => 4,
            'swir2' => 5,
            'swir3' => 6,
        ],
        'sentinel2' => [
            'B01' => 0,
            'B02' => 1, // blue
            'B03' => 2, // green
            'B04' => 3, // red
            'B05' => 4,
            'B06' => 5,
            'B07' => 6,
            'B08' => 7, // nir
            'B8A' => 8,
            'B09' => 9,
            'B10' => 10,
            'B11' => 11, // swir1
            'B12' => 12, // swir2
        ],
        'landsat' => [
            'blue' => 0,
            'green' => 1,
            'red' => 2,
            'nir' => 3,
            'swir1' => 4,
            'swir2' => 5,
            'pan' => 6,
            'cirrus' => 7,
            'qa' => 8,
        ],
    ];

    /**
     * Calculate a spectral index from image data.
     *
     * @param array $bands Array of band data or file paths
     * @param string $index Index name (e.g., 'NDVI', 'iron_oxide')
     * @param array $options Calculation options
     * @return array Index values and metadata
     */
    public function calculateIndex(array $bands, string $index, array $options = []): array
    {
        $index = strtolower($index);

        if (!isset(self::INDICES[$index])) {
            throw new \InvalidArgumentException(
                "Unknown spectral index: {$index}. Available: " . implode(', ', array_keys(self::INDICES))
            );
        }

        $indexInfo = self::INDICES[$index];
        $bandMapping = $options['band_mapping'] ?? self::BAND_MAPPINGS['standard'];

        Log::info('Calculating spectral index', [
            'index' => $index,
            'formula' => $indexInfo['formula'],
        ]);

        // Get band data
        $bandData = $this->extractBands($bands, $indexInfo['bands'], $bandMapping);

        // Calculate index based on formula
        $result = $this->applyFormula($index, $bandData, $bandMapping);

        // Calculate statistics
        $flatResult = array_merge(...array_map(fn($row) => array_values($row), $result));
        $validValues = array_filter($flatResult, fn($v) => is_finite($v));

        $statistics = [
            'index' => $index,
            'name' => $indexInfo['name'],
            'formula' => $indexInfo['formula'],
            'min' => min($validValues) ?: 0,
            'max' => max($validValues) ?: 0,
            'mean' => array_sum($validValues) / count($validValues),
            'std' => $this->calculateStd($validValues),
            'valid_pixels' => count($validValues),
            'total_pixels' => count($flatResult),
            'description' => $indexInfo['description'],
        ];

        return [
            'values' => $result,
            'statistics' => $statistics,
            'index_info' => $indexInfo,
        ];
    }

    /**
     * Calculate multiple indices at once.
     *
     * @param array $bands Array of band data
     * @param array $indices List of index names
     * @param array $options Calculation options
     * @return array Results for all indices
     */
    public function calculateMultiple(array $bands, array $indices, array $options = []): array
    {
        $results = [];

        foreach ($indices as $index) {
            $results[$index] = $this->calculateIndex($bands, $index, $options);
        }

        return $results;
    }

    /**
     * Extract required bands from band data array.
     */
    protected function extractBands(array $bands, array $requiredBands, array $bandMapping): array
    {
        $result = [];

        foreach ($requiredBands as $name) {
            if (isset($bandMapping[$name]) && isset($bands[$bandMapping[$name]])) {
                $result[$name] = $bands[$bandMapping[$name]];
            } elseif (isset($bands[$name])) {
                $result[$name] = $bands[$name];
            } else {
                throw new \InvalidArgumentException(
                    "Required band '{$name}' not found in band data. " .
                    "Available bands: " . implode(', ', array_keys($bands))
                );
            }
        }

        return $result;
    }

    /**
     * Apply formula for specific index.
     */
    protected function applyFormula(string $index, array $bandData, array $bandMapping): array
    {
        return match ($index) {
            'ndvi' => $this->ndvi($bandData, $bandMapping),
            'ndwi' => $this->ndwi($bandData, $bandMapping),
            'ndmi' => $this->ndmi($bandData, $bandMapping),
            'iron_oxide' => $this->ironOxide($bandData, $bandMapping),
            'clay' => $this->clayRatio($bandData, $bandMapping),
            'ferrous' => $this->ferrousIron($bandData, $bandMapping),
            'gosi' => $this->gosi($bandData, $bandMapping),
            'ndsi' => $this->ndsi($bandData, $bandMapping),
            'mndwi' => $this->mndwi($bandData, $bandMapping),
            'awesh' => $this->awesh($bandData, $bandMapping),
            default => $this->genericIndex($index, $bandData),
        };
    }

    /**
     * Calculate NDVI.
     */
    protected function ndvi(array $bandData, array $bandMapping): array
    {
        $nir = $bandData['nir'];
        $red = $bandData['red'];
        
        return $this->binaryOperation($nir, $red, function ($n, $r) {
            $sum = $n + $r;
            return $sum !== 0 ? ($n - $r) / $sum : 0;
        });
    }

    /**
     * Calculate NDWI.
     */
    protected function ndwi(array $bandData, array $bandMapping): array
    {
        $green = $bandData['green'];
        $nir = $bandData['nir'];
        
        return $this->binaryOperation($green, $nir, function ($g, $n) {
            $sum = $g + $n;
            return $sum !== 0 ? ($g - $n) / $sum : 0;
        });
    }

    /**
     * Calculate NDMI.
     */
    protected function ndmi(array $bandData, array $bandMapping): array
    {
        $nir = $bandData['nir'];
        $swir1 = $bandData['swir1'];
        
        return $this->binaryOperation($nir, $swir1, function ($n, $s) {
            $sum = $n + $s;
            return $sum !== 0 ? ($n - $s) / $sum : 0;
        });
    }

    /**
     * Calculate Iron Oxide Ratio.
     */
    protected function ironOxide(array $bandData, array $bandMapping): array
    {
        $red = $bandData['red'];
        $blue = $bandData['blue'];
        
        return $this->binaryOperation($red, $blue, function ($r, $b) {
            return $b !== 0 ? $r / $b : 0;
        });
    }

    /**
     * Calculate Clay Ratio.
     */
    protected function clayRatio(array $bandData, array $bandMapping): array
    {
        $swir1 = $bandData['swir1'];
        $swir2 = $bandData['swir2'];
        
        return $this->binaryOperation($swir1, $swir2, function ($s1, $s2) {
            return $s2 !== 0 ? $s1 / $s2 : 0;
        });
    }

    /**
     * Calculate Ferrous Iron Index.
     */
    protected function ferrousIron(array $bandData, array $bandMapping): array
    {
        $nir = $bandData['nir'];
        $swir1 = $bandData['swir1'];
        
        return $this->binaryOperation($nir, $swir1, function ($n, $s) {
            $sum = $n + $s;
            return $sum !== 0 ? ($n - $s) / $sum : 0;
        });
    }

    /**
     * Calculate GOSI (GOES Soil Index).
     */
    protected function gosi(array $bandData, array $bandMapping): array
    {
        $red = $bandData['red'];
        $nir = $bandData['nir'];
        
        return $this->binaryOperation($red, $nir, function ($r, $n) {
            $sum = $r + $n;
            return $sum !== 0 ? ($r - $n) / $sum : 0;
        });
    }

    /**
     * Calculate NDSI (Snow Index).
     */
    protected function ndsi(array $bandData, array $bandMapping): array
    {
        $green = $bandData['green'];
        $swir1 = $bandData['swir1'];
        
        return $this->binaryOperation($green, $swir1, function ($g, $s) {
            $sum = $g + $s;
            return $sum !== 0 ? ($g - $s) / $sum : 0;
        });
    }

    /**
     * Calculate MNDWI (Modified NDWI).
     */
    protected function mndwi(array $bandData, array $bandMapping): array
    {
        $green = $bandData['green'];
        $swir1 = $bandData['swir1'];
        
        return $this->binaryOperation($green, $swir1, function ($g, $s) {
            $sum = $g + $s;
            return $sum !== 0 ? ($g - $s) / $sum : 0;
        });
    }

    /**
     * Calculate AWESH (Automated Water Exclusion Shadow).
     */
    protected function awesh(array $bandData, array $bandMapping): array
    {
        $blue = $bandData['blue'];
        $green = $bandData['green'];
        $red = $bandData['red'];
        $nir = $bandData['nir'];
        $swir1 = $bandData['swir1'];
        $swir2 = $bandData['swir2'];

        return $this->pixelwiseSum([
            $blue, $green, $red, $nir, $swir1, $swir2
        ], function ($values) {
            return array_sum($values) / 6;
        });
    }

    /**
     * Generic index calculator using expression.
     */
    protected function genericIndex(string $index, array $bandData): array
    {
        // Default implementation - should not be reached
        throw new \InvalidArgumentException("No implementation for index: {$index}");
    }

    /**
     * Apply binary operation to two band arrays.
     */
    protected function binaryOperation(array $band1, array $band2, callable $operation): array
    {
        $height = count($band1);
        $width = count($band1[0] ?? []);
        $result = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $v1 = $band1[$y][$x] ?? 0;
                $v2 = $band2[$y][$x] ?? 0;
                $row[] = $operation($v1, $v2);
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Apply operation to multiple bands.
     */
    protected function pixelwiseSum(array $bands, callable $operation): array
    {
        if (empty($bands)) {
            return [];
        }

        $height = count($bands[0]);
        $width = count($bands[0][0] ?? []);
        $result = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $values = [];
                foreach ($bands as $band) {
                    $values[] = $band[$y][$x] ?? 0;
                }
                $row[] = $operation($values);
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Calculate standard deviation.
     */
    protected function calculateStd(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        return sqrt(array_sum($squaredDiffs) / (count($values) - 1));
    }

    /**
     * Get list of available indices.
     */
    public function getAvailableIndices(): array
    {
        return array_map(fn($k, $v) => [
            'key' => $k,
            'name' => $v['name'],
            'description' => $v['description'],
        ], array_keys(self::INDICES), self::INDICES);
    }

    /**
     * Get index information.
     */
    public function getIndexInfo(string $index): ?array
    {
        return self::INDICES[strtolower($index)] ?? null;
    }

    /**
     * Get algorithm name.
     */
    public function getName(): string
    {
        return 'Spectral Calculator';
    }

    /**
     * Get default parameters.
     */
    public function getDefaultParameters(): array
    {
        return [];
    }
}

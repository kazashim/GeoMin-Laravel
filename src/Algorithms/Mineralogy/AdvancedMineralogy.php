<?php

namespace GeoMin\Algorithms\Mineralogy;

use GeoMin\Algorithms\BaseAlgorithm;
use Illuminate\Support\Facades\Log;

/**
 * Advanced Mineralogy Analyzer
 * 
 * Implements advanced mineral detection algorithms for mapping
 * hydrothermal alteration and mineral deposits in satellite imagery.
 * 
 * Features:
 * - Crosta PCA (Directed PCA) for alteration mapping
 * - Spectral Angle Mapper (SAM) for reference spectrum matching
 * - Linear Spectral Unmixing for mineral abundance estimation
 * - Reference spectrum library (USGS splib)
 * 
 * @author Kazashim Kuzasuwat
 */
class AdvancedMineralogy extends BaseAlgorithm
{
    /**
     * Common hydrothermal alteration mineral signatures.
     * Typical wavelength positions and spectral characteristics.
     */
    public const ALTERATION_MINERALS = [
        'kaolinite' => [
            'absorption' => 2.17,
            'features' => [1.4, 1.8, 2.17, 2.2],
            'type' => 'clay',
        ],
        'alunite' => [
            'absorption' => 2.17,
            'features' => [1.4, 1.76, 2.17, 2.2],
            'type' => 'sulfate',
        ],
        'jarosite' => [
            'absorption' => 2.27,
            'features' => [1.4, 1.76, 2.27, 2.4],
            'type' => 'sulfate',
        ],
        'hematite' => [
            'absorption' => 0.85,
            'features' => [0.55, 0.65, 0.85],
            'type' => 'iron_oxide',
        ],
        'goethite' => [
            'absorption' => 0.92,
            'features' => [0.55, 0.65, 0.92],
            'type' => 'iron_oxide',
        ],
        'sericite' => [
            'absorption' => 2.2,
            'features' => [1.4, 2.2, 2.35],
            'type' => 'mica',
        ],
        'chlorite' => [
            'absorption' => 2.3,
            'features' => [1.4, 1.9, 2.3, 2.35],
            'type' => 'phyllosilicate',
        ],
        'calcite' => [
            'absorption' => 2.33,
            'features' => [1.4, 1.9, 2.0, 2.33, 2.55],
            'type' => 'carbonate',
        ],
        'dolomite' => [
            'absorption' => 2.31,
            'features' => [1.4, 1.9, 2.31, 2.52],
            'type' => 'carbonate',
        ],
    ];

    /**
     * Sentinel-2 band wavelengths (micrometers).
     */
    public const SENTINEL2_WAVELENGTHS = [
        'B01' => 0.443,
        'B02' => 0.492,
        'B03' => 0.560,
        'B04' => 0.665,
        'B05' => 0.705,
        'B06' => 0.740,
        'B07' => 0.783,
        'B08' => 0.842,
        'B8A' => 0.865,
        'B09' => 0.945,
        'B11' => 1.610,
        'B12' => 2.190,
    ];

    /**
     * Perform Crosta PCA for alteration mapping.
     *
     * The Crosta technique identifies specific minerals by analyzing
     * PCA component loadings to find components that contrast
     * absorption features.
     *
     * @param array|string $data Image data array or file path
     * @param string $targetMineral Target mineral type ('hydroxyl', 'iron', 'silica')
     * @param int $nComponents Number of PCA components
     * @param array $options Additional options
     * @return array PCA results with components and mineral maps
     */
    public function crostaPCA($data, string $targetMineral = 'hydroxyl', int $nComponents = 4, array $options = []): array
    {
        $options = array_merge([
            'bands' => ['B02', 'B03', 'B04', 'B08', 'B11', 'B12'],
        ], $options);

        $imageData = $this->loadData($data);
        $pixelData = $this->preparePixelData($imageData, $options['bands']);

        if (empty($pixelData)) {
            throw new \InvalidArgumentException('No valid pixel data found');
        }

        Log::info('Performing Crosta PCA', [
            'target_mineral' => $targetMineral,
            'n_components' => $nComponents,
            'pixels' => count($pixelData),
        ]);

        // Calculate PCA
        $pcaResult = $this->calculatePCA($pixelData, $nComponents);

        // Identify mineral components
        $mineralComponents = $this->identifyMineralComponents(
            $pcaResult['loadings'],
            $options['bands'],
            $targetMineral
        );

        // Reshape components to image
        $components = $this->reshapeComponents($pcaResult['components'], $imageData, $nComponents);

        $statistics = [
            'n_components' => $nComponents,
            'explained_variance_ratio' => $pcaResult['explained_variance_ratio'],
            'cumulative_variance' => array_sum($pcaResult['explained_variance_ratio']),
            'target_mineral' => $targetMineral,
            'mineral_components' => $mineralComponents,
        ];

        return [
            'components' => $components,
            'loadings' => $pcaResult['loadings'],
            'explained_variance' => $pcaResult['explained_variance_ratio'],
            'mineral_components' => $mineralComponents,
            'statistics' => $statistics,
        ];
    }

    /**
     * Calculate Spectral Angle Mapper similarity.
     *
     * Measures similarity between pixel spectra and reference spectrum
     * using the angle between vectors in n-dimensional space.
     *
     * @param array|string $data Image data array or file path
     * @param array $referenceSpectrum Reference mineral spectrum
     * @param float $threshold SAM threshold (lower = more similar)
     * @param array $options Additional options
     * @return array SAM results with similarity map
     */
    public function spectralAngleMapper($data, array $referenceSpectrum, float $threshold = 0.1, array $options = []): array
    {
        $imageData = $this->loadData($data);

        Log::info('Calculating Spectral Angle Mapper', [
            'threshold' => $threshold,
            'reference_length' => count($referenceSpectrum),
        ]);

        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $samValues = array_fill(0, $height, array_fill(0, $width, 0.0));

        // Normalize reference spectrum
        $refNorm = $this->normalizeVector($referenceSpectrum);

        // Calculate SAM for each pixel
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixel($imageData, $x, $y);
                
                if (empty($pixel)) {
                    $samValues[$y][$x] = M_PI_2; // Maximum angle for invalid pixels
                    continue;
                }

                // Normalize pixel spectrum
                $pixelNorm = $this->normalizeVector($pixel);

                // Calculate angle (in radians)
                $dotProduct = $this->dotProduct($refNorm, $pixelNorm);
                $dotProduct = max(-1.0, min(1.0, $dotProduct));
                $angle = acos($dotProduct);

                $samValues[$y][$x] = $angle;
            }
        }

        // Create mask of matches
        $matches = array_map(
            fn($row) => array_map(fn($v) => $v < $threshold, $row),
            $samValues
        );

        $nMatches = array_sum(...array_map(fn($row) => array_map(fn($m) => $m ? 1 : 0, $row), $matches));

        $statistics = [
            'method' => 'spectral_angle_mapper',
            'threshold' => $threshold,
            'total_pixels' => $height * $width,
            'matches' => $nMatches,
            'match_percentage' => ($nMatches / ($height * $width)) * 100,
            'min_angle' => min(...array_merge(...$samValues)),
            'max_angle' => max(...array_merge(...$samValues)),
            'mean_angle' => array_sum(...array_map('array_sum', $samValues)) / ($height * $width),
        ];

        return [
            'sam_values' => $samValues,
            'matches' => $matches,
            'threshold' => $threshold,
            'statistics' => $statistics,
        ];
    }

    /**
     * Perform linear spectral unmixing.
     *
     * Decomposes pixel spectra into abundance fractions of endmembers.
     *
     * @param array|string $data Image data array or file path
     * @param array $endmembers Endmember spectra dictionary [name => spectrum]
     * @param array $options Additional options
     * @return array Unmixing results with abundance maps
     */
    public function unmix($data, array $endmembers, array $options = []): array
    {
        $options = array_merge([
            'sum_to_one' => true,
            'non_negative' => true,
        ], $options);

        $imageData = $this->loadData($data);
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $nBands = count($imageData[0][0] ?? $imageData[0] ?? []);
        $nPixels = $height * $width;
        $endmemberNames = array_keys($endmembers);
        $nEndmembers = count($endmemberNames);

        // Validate endmember dimensions
        foreach ($endmembers as $name => $spectrum) {
            if (count($spectrum) !== $nBands) {
                throw new \InvalidArgumentException(
                    "Endmember '{$name}' has wrong number of bands. Expected {$nBands}, got " . count($spectrum)
                );
            }
        }

        Log::info('Performing Linear Spectral Unmixing', [
            'endmembers' => $endmemberNames,
            'image_size' => "{$width}x{$height}",
        ]);

        // Build endmember matrix
        $E = [];
        for ($i = 0; $i < $nBands; $i++) {
            $E[$i] = [];
            for ($j = 0; $j < $nEndmembers; $j++) {
                $E[$i][$j] = $endmembers[$endmemberNames[$j]][$i];
            }
        }

        // Calculate pseudo-inverse
        $E_pinv = $this->matrixPseudoInverse($E);

        // Initialize abundance maps
        $abundances = [];
        foreach ($endmemberNames as $name) {
            $abundances[$name] = array_fill(0, $height, array_fill(0, $width, 0.0));
        }

        $residual = [];
        $rmse = 0;
        $validPixels = 0;

        // Process each pixel
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixel($imageData, $x, $y);
                
                // Skip if invalid
                if (empty($pixel) || !array_reduce($pixel, fn($c, $v) => $c && is_finite($v), true)) {
                    continue;
                }

                // Calculate abundances using pseudo-inverse
                $a = [];
                for ($j = 0; $j < $nEndmembers; $j++) {
                    $sum = 0;
                    for ($i = 0; $i < $nBands; $i++) {
                        $sum += $E_pinv[$j][$i] * $pixel[$i];
                    }
                    $a[$j] = $sum;
                }

                // Apply constraints
                if ($options['non_negative']) {
                    $a = array_map(fn($v) => max(0, $v), $a);
                }

                if ($options['sum_to_one']) {
                    $sum = array_sum($a);
                    if ($sum > 0) {
                        $a = array_map(fn($v) => $v / $sum, $a);
                    }
                }

                // Store abundances
                for ($j = 0; $j < $nEndmembers; $j++) {
                    $abundances[$endmemberNames[$j]][$y][$x] = $a[$j];
                }

                // Calculate residual
                $reconstructed = [];
                for ($i = 0; $i < $nBands; $i++) {
                    $sum = 0;
                    for ($j = 0; $j < $nEndmembers; $j++) {
                        $sum += $E[$i][$j] * $a[$j];
                    }
                    $reconstructed[$i] = $sum;
                }

                $resid = 0;
                for ($i = 0; $i < $nBands; $i++) {
                    $diff = $pixel[$i] - $reconstructed[$i];
                    $resid += $diff * $diff;
                }
                $rmse += $resid;
                $validPixels++;
            }
        }

        $rmse = $validPixels > 0 ? sqrt($rmse / ($validPixels * $nBands)) : 0;

        // Statistics
        $statistics = [
            'method' => 'linear_spectral_unmixing',
            'endmembers' => $endmemberNames,
            'sum_to_one' => $options['sum_to_one'],
            'non_negative' => $options['non_negative'],
            'valid_pixels' => $validPixels,
            'rmse' => $rmse,
            'mean_abundances' => [],
        ];

        foreach ($endmemberNames as $name) {
            $values = array_merge(...$abundances[$name]);
            $statistics['mean_abundances'][$name] = array_sum($values) / count($values);
        }

        return [
            'abundances' => $abundances,
            'endmembers' => $endmembers,
            'rmse' => $rmse,
            'statistics' => $statistics,
        ];
    }

    /**
     * Get reference spectrum for a mineral.
     *
     * @param string $mineral Mineral name
     * @param string $source Spectral library source ('usgs')
     * @return array Reference spectrum values
     */
    public function getReferenceSpectrum(string $mineral, string $source = 'usgs'): array
    {
        $mineral = strtolower($mineral);

        $mineralSpectra = [
            'kaolinite' => [0.15, 0.20, 0.25, 0.35, 0.45, 0.35],
            'alunite' => [0.18, 0.22, 0.28, 0.38, 0.50, 0.40],
            'jarosite' => [0.22, 0.28, 0.35, 0.40, 0.42, 0.38],
            'hematite' => [0.30, 0.35, 0.28, 0.40, 0.50, 0.48],
            'goethite' => [0.28, 0.32, 0.30, 0.42, 0.52, 0.50],
            'sericite' => [0.16, 0.21, 0.26, 0.36, 0.48, 0.42],
            'chlorite' => [0.18, 0.22, 0.25, 0.30, 0.35, 0.32],
            'calcite' => [0.20, 0.25, 0.30, 0.40, 0.45, 0.42],
            'dolomite' => [0.19, 0.24, 0.28, 0.38, 0.44, 0.41],
            'muscovite' => [0.16, 0.20, 0.25, 0.35, 0.46, 0.40],
            'biotite' => [0.12, 0.15, 0.18, 0.25, 0.32, 0.28],
            'quartz' => [0.22, 0.28, 0.35, 0.45, 0.55, 0.52],
            'feldspar' => [0.20, 0.25, 0.30, 0.40, 0.48, 0.45],
            'vegetation' => [0.08, 0.12, 0.10, 0.45, 0.35, 0.25],
            'soil' => [0.18, 0.22, 0.26, 0.32, 0.38, 0.35],
            'water' => [0.05, 0.08, 0.04, 0.02, 0.01, 0.01],
        ];

        if (isset($mineralSpectra[$mineral])) {
            return $mineralSpectra[$mineral];
        }

        throw new \InvalidArgumentException(
            "Unknown mineral: {$mineral}. Available: " . implode(', ', array_keys($mineralSpectra))
        );
    }

    /**
     * Get available minerals in the reference library.
     *
     * @return array List of available mineral names
     */
    public function getAvailableMinerals(): array
    {
        return array_keys(self::ALTERATION_MINERALS);
    }

    /**
     * Create endmember dictionary from mineral list.
     *
     * @param array $minerals List of mineral names
     * @return array Dictionary mapping mineral names to spectra
     */
    public function createEndmemberDict(array $minerals): array
    {
        return array_combine(
            array_map('ucfirst', $minerals),
            array_map([$this, 'getReferenceSpectrum'], $minerals)
        );
    }

    /**
     * Calculate PCA using power iteration method.
     */
    protected function calculatePCA(array $data, int $nComponents): array
    {
        $nSamples = count($data);
        $nFeatures = count($data[0]);

        // Center the data
        $means = array_fill(0, $nFeatures, 0.0);
        foreach ($data as $sample) {
            for ($i = 0; $i < $nFeatures; $i++) {
                $means[$i] += $sample[$i];
            }
        }
        for ($i = 0; $i < $nFeatures; $i++) {
            $means[$i] /= $nSamples;
        }

        $centered = [];
        foreach ($data as $sample) {
            $centered[] = array_map(fn($v, $m) => $v - $m, $sample, $means);
        }

        // Calculate covariance matrix
        $cov = array_fill(0, $nFeatures, array_fill(0, $nFeatures, 0.0));
        foreach ($centered as $sample) {
            for ($i = 0; $i < $nFeatures; $i++) {
                for ($j = 0; $j < $nFeatures; $j++) {
                    $cov[$i][$j] += $sample[$i] * $sample[$j];
                }
            }
        }
        for ($i = 0; $i < $nFeatures; $i++) {
            for ($j = 0; $j < $nFeatures; $j++) {
                $cov[$i][$j] /= ($nSamples - 1);
            }
        }

        // Power iteration for eigenvalues/vectors
        $components = [];
        $eigenvalues = [];
        $covCopy = $cov;

        for ($comp = 0; $comp < min($nComponents, $nFeatures); $comp++) {
            $vector = array_fill(0, $nFeatures, 1.0 / sqrt($nFeatures));
            
            // Power iteration
            for ($iter = 0; $iter < 100; $iter++) {
                $newVector = array_fill(0, $nFeatures, 0.0);
                for ($i = 0; $i < $nFeatures; $i++) {
                    for ($j = 0; $j < $nFeatures; $j++) {
                        $newVector[$i] += $covCopy[$i][$j] * $vector[$j];
                    }
                }
                
                $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $newVector)));
                if ($norm > 0) {
                    $vector = array_map(fn($v) => $v / $norm, $newVector);
                }
            }

            // Calculate eigenvalue
            $eigenvalue = 0;
            for ($i = 0; $i < $nFeatures; $i++) {
                for ($j = 0; $j < $nFeatures; $j++) {
                    $eigenvalue += $vector[$i] * $covCopy[$i][$j] * $vector[$j];
                }
            }

            // Deflate covariance matrix
            for ($i = 0; $i < $nFeatures; $i++) {
                for ($j = 0; $j < $nFeatures; $j++) {
                    $covCopy[$i][$j] -= $eigenvalue * $vector[$i] * $vector[$j];
                }
            }

            $components[] = $vector;
            $eigenvalues[] = $eigenvalue;
        }

        // Calculate explained variance ratio
        $totalVariance = array_sum($eigenvalues);
        $explainedVarianceRatio = array_map(fn($v) => $v / $totalVariance, $eigenvalues);

        // Project data onto components
        $projected = [];
        foreach ($centered as $sample) {
            $projectedSample = [];
            for ($c = 0; $c < count($components); $c++) {
                $sum = 0;
                for ($i = 0; $i < $nFeatures; $i++) {
                    $sum += $sample[$i] * $components[$c][$i];
                }
                $projectedSample[] = $sum;
            }
            $projected[] = $projectedSample;
        }

        return [
            'components' => $projected,
            'loadings' => $components,
            'eigenvalues' => $eigenvalues,
            'explained_variance_ratio' => $explainedVarianceRatio,
        ];
    }

    /**
     * Identify PCA components associated with target minerals.
     */
    protected function identifyMineralComponents(array $loadings, array $bands, string $targetMineral): array
    {
        $mineralComponents = [];
        
        // Get band indices for key wavelengths
        $bandWavelengths = array_map(
            fn($b) => self::SENTINEL2_WAVELENGTHS[$b] ?? 0.5,
            $bands
        );

        if ($targetMineral === 'hydroxyl') {
            // SWIR absorption (B11 at 1.6μm, B12 at 2.2μm)
            $swir1Idx = $this->findWavelengthIndex($bandWavelengths, 1.610);
            $swir2Idx = $this->findWavelengthIndex($bandWavelengths, 2.190);
            
            foreach ($loadings as $i => $loading) {
                if ($swir1Idx >= 0 && $swir2Idx >= 0) {
                    $diff = $loading[$swir2Idx] - $loading[$swir1Idx];
                    if (abs($diff) > 0.3) {
                        $mineralComponents['hydroxyl_alteration'] = $i;
                    }
                }
            }
        } elseif ($targetMineral === 'iron') {
            // Red absorption (B04 at 0.65μm)
            $redIdx = $this->findWavelengthIndex($bandWavelengths, 0.665);
            
            foreach ($loadings as $i => $loading) {
                if ($redIdx >= 0 && $loading[$redIdx] < -0.3) {
                    $mineralComponents['iron_oxide'] = $i;
                }
            }
        } elseif ($targetMineral === 'silica') {
            foreach ($loadings as $i => $loading) {
                $swirMean = 0;
                $count = 0;
                for ($j = 0; $j < count($loading); $j++) {
                    if ($bandWavelengths[$j] > 1.5) {
                        $swirMean += $loading[$j];
                        $count++;
                    }
                }
                if ($count > 0 && ($swirMean / $count) > 0.3) {
                    $mineralComponents['silica'] = $i;
                }
            }
        }

        return $mineralComponents;
    }

    /**
     * Find index of closest wavelength.
     */
    protected function findWavelengthIndex(array $wavelengths, float $target): int
    {
        $closestIdx = -1;
        $closestDiff = PHP_FLOAT_MAX;

        foreach ($wavelengths as $idx => $wavelength) {
            $diff = abs($wavelength - $target);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $closestIdx = $idx;
            }
        }

        return $closestDiff < 0.1 ? $closestIdx : -1;
    }

    /**
     * Reshape PCA components to image dimensions.
     */
    protected function reshapeComponents(array $projected, array $imageData, int $nComponents): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);

        $result = [];
        $idx = 0;

        for ($c = 0; $c < $nComponents; $c++) {
            $component = [];
            for ($y = 0; $y < $height; $y++) {
                $row = [];
                for ($x = 0; $x < $width; $x++) {
                    $row[] = $projected[$idx][$c] ?? 0;
                    $idx++;
                }
                $component[] = $row;
            }
            $result[] = $component;
        }

        return $result;
    }

    /**
     * Normalize vector to unit length.
     */
    protected function normalizeVector(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));
        if ($norm > 0) {
            return array_map(fn($v) => $v / $norm, $vector);
        }
        return $vector;
    }

    /**
     * Calculate dot product of two vectors.
     */
    protected function dotProduct(array $a, array $b): float
    {
        $sum = 0;
        for ($i = 0; $i < min(count($a), count($b)); $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }

    /**
     * Calculate matrix pseudo-inverse.
     */
    protected function matrixPseudoInverse(array $matrix): array
    {
        $m = count($matrix);
        $n = count($matrix[0]);

        // Use SVD approximation via normal equations
        $mt = $this->matrixTranspose($matrix);
        $mtm = $this->matrixMultiply($mt, $matrix);
        $mtmInv = $this->matrixInverse($mtm);

        return $this->matrixMultiply($mtmInv, $mt);
    }

    /**
     * Matrix transpose.
     */
    protected function matrixTranspose(array $matrix): array
    {
        return array_map(null, ...$matrix);
    }

    /**
     * Matrix multiplication.
     */
    protected function matrixMultiply(array $a, array $b): array
    {
        $aRows = count($a);
        $aCols = count($a[0]);
        $bCols = count($b[0]);

        $result = array_fill(0, $aRows, array_fill(0, $bCols, 0.0));

        for ($i = 0; $i < $aRows; $i++) {
            for ($j = 0; $j < $bCols; $j++) {
                for ($k = 0; $k < $aCols; $k++) {
                    $result[$i][$j] += $a[$i][$k] * $b[$k][$j];
                }
            }
        }

        return $result;
    }

    /**
     * Matrix inverse using Gaussian elimination.
     */
    protected function matrixInverse(array $matrix): array
    {
        $n = count($matrix);
        
        // Augment with identity
        $augmented = [];
        for ($i = 0; $i < $n; $i++) {
            $augmented[$i] = array_merge($matrix[$i], array_fill(0, $n, 0.0));
            $augmented[$i][$n + $i] = 1.0;
        }

        // Gaussian elimination
        for ($col = 0; $col < $n; $col++) {
            $maxRow = $col;
            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($augmented[$row][$col]) > abs($augmented[$maxRow][$col])) {
                    $maxRow = $row;
                }
            }

            [$augmented[$col], $augmented[$maxRow]] = [$augmented[$maxRow], $augmented[$col]];

            if (abs($augmented[$col][$col]) < 1e-10) {
                continue;
            }

            $pivot = $augmented[$col][$col];
            for ($j = 0; $j < 2 * $n; $j++) {
                $augmented[$col][$j] /= $pivot;
            }

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
     * Get algorithm name.
     */
    public function getName(): string
    {
        return 'Advanced Mineralogy';
    }

    /**
     * Get default parameters.
     */
    public function getDefaultParameters(): array
    {
        return [];
    }
}

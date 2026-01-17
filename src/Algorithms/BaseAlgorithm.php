<?php

namespace GeoMin\Algorithms;

/**
 * Base Algorithm Class
 * 
 * Abstract base class for all GeoMin algorithms, providing common
 * functionality for data loading, preprocessing, and result formatting.
 * 
 * @author Kazashim Kuzasuwat
 */
abstract class BaseAlgorithm
{
    /**
     * Algorithm name
     */
    abstract public function getName(): string;

    /**
     * Get default parameters
     */
    abstract public function getDefaultParameters(): array;

    /**
     * Load data from file or array.
     *
     * @param array|string $data Image data array or file path
     * @return array Loaded image data
     */
    protected function loadData($data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data)) {
            return $this->loadFromFile($data);
        }

        throw new \InvalidArgumentException('Data must be array or file path');
    }

    /**
     * Load data from file.
     *
     * @param string $filePath Path to data file
     * @return array Loaded data
     */
    protected function loadFromFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => $this->loadJSON($filePath),
            'csv' => $this->loadCSV($filePath),
            'npy' => $this->loadNPY($filePath),
            default => throw new \InvalidArgumentException("Unsupported file format: {$extension}"),
        };
    }

    /**
     * Load data from JSON file.
     */
    protected function loadJSON(string $filePath): array
    {
        $content = file_get_contents($filePath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Load data from CSV file.
     */
    protected function loadCSV(string $filePath): array
    {
        $data = [];
        $handle = fopen($filePath, 'r');
        
        if ($handle === false) {
            throw new \RuntimeException("Failed to open CSV file: {$filePath}");
        }

        while (($row = fgetcsv($handle)) !== false) {
            $data[] = array_map('floatval', array_filter($row, fn($v) => $v !== ''));
        }

        fclose($handle);
        return $data;
    }

    /**
     * Load NumPy (.npy) file.
     * Note: This is a simplified implementation. For full NPY support,
     * consider using a PHP extension or external library.
     */
    protected function loadNPY(string $filePath): array
    {
        // Simplified NPY loader - reads the header and attempts to parse
        $handle = fopen($filePath, 'rb');
        
        // Read magic bytes
        $magic = fread($handle, 6);
        if ($magic !== "\x93NUMPY") {
            throw new \InvalidArgumentException("Invalid NPY file: {$filePath}");
        }

        // Read version
        $version = unpack('C2', fread($handle, 2));
        $headerLen = $version[1] === 1 ? 2 : 4;
        $headerLength = unpack('V', fread($handle, $headerLen))[1];

        // Read header
        $header = fread($handle, $headerLength);
        $headerData = json_decode($header, true);

        // Read data
        $data = fread($handle, filesize($filePath) - ftell($handle));
        fclose($handle);

        // Parse dtype
        $dtype = $headerData['descr'] ?? '<f8';
        $shape = $headerData['shape'] ?? [];

        // Simple float64 parser
        if ($dtype === '<f8' || $dtype === '=f8') {
            $values = unpack('d*', $data);
            return $this->reshapeToArray($values, $shape);
        }

        throw new \InvalidArgumentException("Unsupported dtype: {$dtype}");
    }

    /**
     * Reshape flat values to multi-dimensional array.
     */
    protected function reshapeToArray(array $values, array $shape): array
    {
        $flat = array_values($values);
        
        if (empty($shape)) {
            return $flat;
        }

        return $this->recursiveReshape($flat, $shape);
    }

    /**
     * Recursively reshape flat array to target shape.
     */
    protected function recursiveReshape(array $flat, array $shape): array
    {
        if (count($shape) === 1) {
            return array_slice($flat, 0, $shape[0]);
        }

        $result = [];
        $size = 1;
        for ($i = 1; $i < count($shape); $i++) {
            $size *= $shape[$i];
        }

        for ($i = 0; $i < $shape[0]; $i++) {
            $result[] = $this->recursiveReshape(
                array_slice($flat, $i * $size, $size),
                array_slice($shape, 1)
            );
        }

        return $result;
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
                if (count($pixel) === count($bandIndices) && !empty($pixel)) {
                    $pixels[] = $pixel;
                }
            }
        }

        return $pixels;
    }

    /**
     * Reshape flat scores to image dimensions.
     *
     * @param array $scores Flat array of scores
     * @param array $imageData Original image data for dimensions
     * @return array 2D/3D array matching image dimensions
     */
    protected function reshapeToImage(array $scores, array $imageData): array
    {
        $height = count($imageData);
        $width = count($imageData[0] ?? []);
        $nBands = count($imageData[0][0] ?? $imageData[0] ?? []);

        // Determine if result should be 2D or 3D
        $is2D = count($scores) === $height * $width ||
                (count($scores) > 0 && !is_array($scores[0]));

        if ($is2D) {
            $result = [];
            $idx = 0;
            for ($y = 0; $y < $height; $y++) {
                $row = [];
                for ($x = 0; $x < $width; $x++) {
                    $row[] = $scores[$idx++] ?? 0;
                }
                $result[] = $row;
            }
            return $result;
        }

        // 3D result
        $result = [];
        $idx = 0;
        for ($b = 0; $b < $nBands; $b++) {
            $band = [];
            for ($y = 0; $y < $height; $y++) {
                $row = [];
                for ($x = 0; $x < $width; $x++) {
                    $row[] = $scores[$idx++][$b] ?? 0;
                }
                $band[] = $row;
            }
            $result[] = $band;
        }
        return $result;
    }

    /**
     * Save result to file.
     *
     * @param array $result Result data
     * @param string $filePath Output file path
     * @param string $format Output format
     */
    protected function saveResult(array $result, string $filePath, string $format = 'json'): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        match ($format) {
            'json' => file_put_contents($filePath, json_encode($result, JSON_PRETTY_PRINT)),
            'csv' => $this->saveCSV($result, $filePath),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    /**
     * Save data to CSV file.
     */
    protected function saveCSV(array $data, string $filePath): void
    {
        $handle = fopen($filePath, 'w');
        
        if ($handle === false) {
            throw new \RuntimeException("Failed to create CSV file: {$filePath}");
        }

        foreach ($data as $row) {
            fputcsv($handle, is_array($row) ? $row : [$row]);
        }

        fclose($handle);
    }

    /**
     * Validate input data.
     *
     * @param array $data Data to validate
     * @return bool True if valid
     */
    protected function validateData(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check if 2D or 3D array
        if (!is_array($data[0] ?? null)) {
            return false;
        }

        return true;
    }

    /**
     * Get memory usage info.
     */
    protected function getMemoryInfo(): array
    {
        return [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];
    }
}

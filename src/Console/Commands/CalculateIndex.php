<?php

namespace GeoMin\Console\Commands;

use GeoMin\Algorithms\Spectral\SpectralCalculator;
use GeoMin\Facades\GeoMin;
use Illuminate\Console\Command;

/**
 * Calculate Spectral Index Command
 * 
 * Calculates spectral indices from satellite band data.
 * 
 * Usage:
 *   php artisan geomin:index path/to/bands.json --index=NDVI
 *   php artisan geomin:index path/to/bands.json --index=iron_oxide,clay --output=results/
 * 
 * @author Kazashim Kuzasuwat
 */
class CalculateIndex extends Command
{
    protected $signature = 'geomin:index
                           {path : Path to band data file (JSON/NumPy)}
                           {--index=NDVI : Spectral index to calculate}
                           {--output= : Output directory for results}
                           {--format=json : Output format (json, csv)}';

    protected $description = 'Calculate spectral indices from satellite imagery';

    protected SpectralCalculator $calculator;

    public function __construct(SpectralCalculator $calculator)
    {
        parent::__construct();
        $this->calculator = $calculator;
    }

    public function handle(): int
    {
        $path = $this->argument('path');
        $indexOption = $this->option('index');
        $outputPath = $this->option('output') ?: storage_path('geomin/indices');
        $format = $this->option('format') ?: 'json';

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return Command::FAILURE;
        }

        // Load band data
        $this->info("Loading band data from: {$path}");
        $bandData = $this->loadBandData($path);

        if (empty($bandData)) {
            $this->error("No valid band data found in file.");
            return Command::FAILURE;
        }

        $this->info("Loaded " . count($bandData) . " bands");

        // Parse indices
        $indices = array_map('trim', explode(',', $indexOption));

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $results = [];

        foreach ($indices as $index) {
            try {
                $this->info("Calculating {$index}...");
                
                $result = $this->calculator->calculateIndex($bandData, $index);
                $results[$index] = $result;

                // Display statistics
                $stats = $result['statistics'];
                $this->info("  Min: {$stats['min']:.4f}");
                $this->info("  Max: {$stats['max']:.4f}");
                $this->info("  Mean: {$stats['mean']:.4f}");
                $this->info("  Valid pixels: {$stats['valid_pixels']}");

                // Save result
                $outputFile = "{$outputPath}/{$index}.{$format}";
                $this->saveResult($result, $outputFile, $format);
                $this->info("  Saved to: {$outputFile}");

            } catch (\Exception $e) {
                $this->error("  Failed to calculate {$index}: {$e->getMessage()}");
            }
        }

        // Save summary
        $summaryFile = "{$outputPath}/summary.json";
        $summary = [
            'timestamp' => now()->toIso8601String(),
            'input_file' => $path,
            'indices_calculated' => array_keys($results),
            'results' => $results,
        ];
        file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT));
        $this->info("Summary saved to: {$summaryFile}");

        return Command::SUCCESS;
    }

    protected function loadBandData(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => json_decode(file_get_contents($path), true),
            'npy' => $this->loadNpy($path),
            default => throw new \InvalidArgumentException("Unsupported format: {$extension}"),
        };
    }

    protected function loadNpy(string $path): array
    {
        // Simplified NPY loader
        $handle = fopen($path, 'rb');
        $magic = fread($handle, 6);
        
        if ($magic !== "\x93NUMPY") {
            throw new \InvalidArgumentException("Invalid NPY file");
        }

        $version = unpack('C2', fread($handle, 2));
        $headerLen = $version[1] === 1 ? 2 : 4;
        $headerLength = unpack('V', fread($handle, $headerLen))[1];
        $header = fread($handle, $headerLength);
        
        $headerData = json_decode($header, true);
        $shape = $headerData['shape'] ?? [];

        $data = fread($handle, filesize($path) - ftell($handle));
        fclose($handle);

        $values = unpack('d*', $data);
        return $this->reshapeToBands($values, $shape);
    }

    protected function reshapeToBands(array $values, array $shape): array
    {
        // Assume first dimension is bands
        if (count($shape) >= 3) {
            $nBands = $shape[0];
            $result = [];
            $bandSize = $shape[1] * $shape[2];
            for ($b = 0; $b < $nBands; $b++) {
                $band = [];
                for ($i = 0; $i < $bandSize; $i++) {
                    $band[] = $values[$b * $bandSize + $i + 1];
                }
                $result[] = $band;
            }
            return $result;
        }

        return array_values($values);
    }

    protected function saveResult(array $result, string $path, string $format): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        match ($format) {
            'json' => file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT)),
            'csv' => $this->saveCsv($result, $path),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    protected function saveCsv(array $result, string $path): void
    {
        $values = $result['values'];
        $handle = fopen($path, 'w');
        
        foreach ($values as $row) {
            fputcsv($handle, $row);
        }
        
        fclose($handle);
    }
}

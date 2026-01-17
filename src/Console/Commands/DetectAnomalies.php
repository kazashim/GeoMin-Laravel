<?php

namespace GeoMin\Console\Commands;

use GeoMin\Facades\GeoMin;
use Illuminate\Console\Command;

/**
 * Detect Anomalies Command
 * 
 * Runs anomaly detection algorithms on satellite imagery to
 * identify potential mineral deposits and mining activity.
 * 
 * Usage:
 *   php artisan geomin:detect path/to/image.json --algorithm=rx
 *   php artisan geomin:detect path/to/image.json --algorithm=isolation_forest --contamination=0.02
 * 
 * @author Kazashim Kuzasuwat
 */
class DetectAnomalies extends Command
{
    protected $signature = 'geomin:detect
                           {path : Path to image data file}
                           {--algorithm=rx : Detection algorithm (rx, isolation_forest, lof)}
                           {--contamination=0.01 : Expected anomaly proportion}
                           {--output= : Output directory for results}
                           {--top=100 : Number of top anomalies to report}
                           {--queue : Dispatch to queue instead of running synchronously}';

    protected $description = 'Detect spectral anomalies in satellite imagery';

    public function handle(): int
    {
        $path = $this->argument('path');
        $algorithm = $this->option('algorithm');
        $contamination = (float) $this->option('contamination');
        $outputPath = $this->option('output') ?: storage_path('geomin/anomalies');
        $topN = (int) $this->option('top');
        $queue = $this->option('queue');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return Command::FAILURE;
        }

        $this->info("Loading image data from: {$path}");
        $imageData = $this->loadImageData($path);

        if (empty($imageData)) {
            $this->error("No valid image data found.");
            return Command::FAILURE;
        }

        $this->info("Image size: " . count($imageData) . "x" . count($imageData[0] ?? []));

        if ($queue) {
            return $this->dispatchToQueue($path, $algorithm, $contamination, $outputPath);
        }

        try {
            $this->info("Running {$algorithm} anomaly detection...");
            $this->info("Contamination rate: {$contamination}");

            $result = GeoMin::detectAnomalies($imageData, $algorithm, [
                'contamination' => $contamination,
                'top_n' => $topN,
            ]);

            $stats = $result['statistics'];
            $this->newLine();
            $this->info("Detection complete:");
            $this->info("  Method: {$stats['method']}");
            $this->info("  Total pixels: {$stats['total_pixels']}");
            $this->info("  Anomaly pixels: {$stats['anomaly_pixels']}");
            $this->info("  Anomaly percentage: {$stats['anomaly_percentage']:.2f}%");

            // Display top anomalies
            if (!empty($result['top_locations'])) {
                $this->newLine();
                $this->info("Top " . count($result['top_locations']) . " Anomaly Locations:");
                $headers = ['Rank', 'X', 'Y', 'Score'];
                $rows = array_map(function ($location, $rank) {
                    return [
                        $rank + 1,
                        $location['coordinates']['x'],
                        $location['coordinates']['y'],
                        number_format($location['score'], 4),
                    ];
                }, $result['top_locations'], array_keys($result['top_locations']));

                $this->table($headers, $rows);
            }

            // Save results
            if (!is_dir($outputPath)) {
                mkdir($outputPath, 0755, true);
            }

            $outputFile = "{$outputPath}/anomaly_results_" . time() . ".json";
            file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));
            $this->info("Results saved to: {$outputFile}");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Detection failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    protected function loadImageData(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => json_decode(file_get_contents($path), true),
            default => throw new \InvalidArgumentException("Unsupported format: {$extension}"),
        };
    }

    protected function dispatchToQueue(string $path, string $algorithm, float $contamination, string $outputPath): int
    {
        $this->info("Dispatching anomaly detection to queue...");
        
        $analysis = GeoMin::dispatchProcessingJob($path, 'anomaly_detection', [
            'algorithm' => $algorithm,
            'contamination' => $contamination,
            'output_path' => $outputPath,
        ]);

        $this->info("Analysis job dispatched. ID: {$analysis->id}");
        $this->info("Check status with: php artisan geomin:status {$analysis->id}");

        return Command::SUCCESS;
    }
}

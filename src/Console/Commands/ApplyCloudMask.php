<?php

namespace GeoMin\Console\Commands;

use GeoMin\Facades\GeoMin;
use Illuminate\Console\Command;

/**
 * Apply Cloud Mask Command
 * 
 * Applies cloud detection and masking to satellite imagery.
 * 
 * Usage:
 *   php artisan geomin:mask path/to/image.json --algorithm=sentinel2
 *   php artisan geomin:mask path/to/image.json --output=/path/to/output
 * 
 * @author Kazashim Kuzasuwat
 */
class ApplyCloudMask extends Command
{
    protected $signature = 'geomin:mask
                           {path : Path to image data file}
                           {--algorithm=sentinel2 : Cloud masking algorithm}
                           {--output= : Output directory for masked data}
                           {--fill-value=0 : Value to use for masked pixels}
                           {--visualize : Generate cloud probability visualization}';

    protected $description = 'Apply cloud masking to satellite imagery';

    public function handle(): int
    {
        $path = $this->argument('path');
        $algorithm = $this->option('algorithm');
        $outputPath = $this->option('output') ?: storage_path('geomin/masked');
        $fillValue = (float) $this->option('fill-value');
        $visualize = $this->option('visualize');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return Command::FAILURE;
        }

        $imageData = $this->loadImageData($path);

        if (empty($imageData)) {
            $this->error("No valid image data found.");
            return Command::FAILURE;
        }

        $this->info("Applying {$algorithm} cloud masking...");
        $this->info("Image size: " . count($imageData) . "x" . count($imageData[0] ?? []));

        try {
            $result = GeoMin::cloudMasker()->mask($imageData, $algorithm);

            $stats = $result['statistics'];
            $this->newLine();
            $this->info("Cloud masking complete:");
            $this->info("  Algorithm: {$stats['algorithm']}");
            $this->info("  Total pixels: {$stats['total_pixels']}");
            $this->info("  Cloud pixels: {$stats['cloud_pixels']}");
            $this->info("  Cloud percentage: {$stats['cloud_percentage']:.2f}%");
            $this->info("  Clear pixels: {$stats['clear_pixels']}");

            if (!is_dir($outputPath)) {
                mkdir($outputPath, 0755, true);
            }

            // Save cloud mask
            $maskFile = "{$outputPath}/cloud_mask_" . time() . ".json";
            file_put_contents($maskFile, json_encode($result['mask'], JSON_PRETTY_PRINT));
            $this->info("Cloud mask saved to: {$maskFile}");

            // Save masked image
            $maskedData = GeoMin::cloudMasker()->applyMask($imageData, $result['mask'], $fillValue);
            $maskedFile = "{$outputPath}/masked_image_" . time() . ".json";
            file_put_contents($maskedFile, json_encode($maskedData, JSON_PRETTY_PRINT));
            $this->info("Masked image saved to: {$maskedFile}");

            // Save statistics
            $statsFile = "{$outputPath}/masking_stats_" . time() . ".json";
            file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
            $this->info("Statistics saved to: {$statsFile}");

            // Visualize if requested
            if ($visualize && $result['probability'] !== null) {
                $vizFile = "{$outputPath}/cloud_probability_" . time() . ".json";
                file_put_contents($vizFile, json_encode($result['probability'], JSON_PRETTY_PRINT));
                $this->info("Cloud probability visualization saved to: {$vizFile}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Cloud masking failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    protected function loadImageData(string $path): array
    {
        return json_decode(file_get_contents($path), true);
    }
}

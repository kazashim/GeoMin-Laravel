<?php

namespace GeoMin\Console\Commands;

use GeoMin\Facades\GeoMin;
use Illuminate\Console\Command;

/**
 * Run Mineral Mapping Command
 * 
 * Performs advanced mineralogy analysis including Crosta PCA,
 * Spectral Angle Mapper, and Spectral Unmixing.
 * 
 * Usage:
 *   php artisan geomin:mineral path/to/image.json --method=crosta --target=hydroxyl
 *   php artisan geomin:mineral path/to/image.json --method=sam --mineral=kaolinite
 * 
 * @author Kazashim Kuzasuwat
 */
class RunMineralMapping extends Command
{
    protected $signature = 'geomin:mineral
                           {path : Path to image data file}
                           {--method=crosta : Analysis method (crosta, sam, unmixing)}
                           {--target=hydroxyl : Target for Crosta PCA (hydroxyl, iron, silica)}
                           {--mineral= : Mineral name for SAM}
                           {--endmembers= : Comma-separated minerals for unmixing}
                           {--output= : Output directory for results}';

    protected $description = 'Perform advanced mineralogy analysis';

    public function handle(): int
    {
        $path = $this->argument('path');
        $method = $this->option('method');
        $target = $this->option('target');
        $mineral = $this->option('mineral');
        $endmembers = $this->option('endmembers');
        $outputPath = $this->option('output') ?: storage_path('geomin/minerals');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return Command::FAILURE;
        }

        $imageData = $this->loadImageData($path);

        if (empty($imageData)) {
            $this->error("No valid image data found.");
            return Command::FAILURE;
        }

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        try {
            return match ($method) {
                'crosta' => $this->runCrostaPCA($imageData, $target, $outputPath),
                'sam' => $this->runSAM($imageData, $mineral, $outputPath),
                'unmixing' => $this->runUnmixing($imageData, $endmembers, $outputPath),
                default => $this->runAll($imageData, $outputPath),
            };

        } catch (\Exception $e) {
            $this->error("Analysis failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    protected function runCrostaPCA(array $imageData, string $target, string $outputPath): int
    {
        $this->info("Running Crosta PCA for {$target} alteration...");

        $result = GeoMin::crostaPCA($imageData, $target);

        $stats = $result['statistics'];
        $this->info("Analysis complete:");
        $this->info("  Components: {$stats['n_components']}");
        $this->info("  Cumulative variance: {$stats['cumulative_variance']:.2%}");
        $this->info("  Target mineral: {$stats['target_mineral']}");

        if (!empty($stats['mineral_components'])) {
            $this->info("  Mineral components:");
            foreach ($stats['mineral_components'] as $mineral => $component) {
                $this->info("    - {$mineral}: Component {$component}");
            }
        }

        $outputFile = "{$outputPath}/crosta_{$target}_" . time() . ".json";
        file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));
        $this->info("Results saved to: {$outputFile}");

        return Command::SUCCESS;
    }

    protected function runSAM(array $imageData, string $mineral, string $outputPath): int
    {
        if (!$mineral) {
            $this->error("Mineral name required for SAM analysis.");
            return Command::FAILURE;
        }

        $this->info("Running Spectral Angle Mapper for {$mineral}...");

        $spectrum = GeoMin::getReferenceSpectrum($mineral);
        $this->info("Reference spectrum: " . implode(', ', $spectrum));

        $result = GeoMin::spectralAngleMapper($imageData, $spectrum);

        $stats = $result['statistics'];
        $this->info("Analysis complete:");
        $this->info("  Matches: {$stats['matches']}");
        $this->info("  Match percentage: {$stats['match_percentage']:.2f}%");
        $this->info("  Mean angle: {$stats['mean_angle']:.4f} radians");

        $outputFile = "{$outputPath}/sam_{$mineral}_" . time() . ".json";
        file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));
        $this->info("Results saved to: {$outputFile}");

        return Command::SUCCESS;
    }

    protected function runUnmixing(array $imageData, string $endmembersStr, string $outputPath): int
    {
        if (!$endmembersStr) {
            $this->error("Endmembers required for spectral unmixing.");
            return Command::FAILURE;
        }

        $endmembers = array_map('trim', explode(',', $endmembersStr));
        $this->info("Running Linear Spectral Unmixing with: " . implode(', ', $endmembers));

        $endmemberDict = GeoMin::mineralogy()->createEndmemberDict($endmembers);

        $result = GeoMin::spectralUnmixing($imageData, $endmemberDict);

        $stats = $result['statistics'];
        $this->info("Analysis complete:");
        $this->info("  RMSE: {$stats['rmse']:.4f}");
        $this->info("  Valid pixels: {$stats['valid_pixels']}");

        if (!empty($stats['mean_abundances'])) {
            $this->info("  Mean abundances:");
            foreach ($stats['mean_abundances'] as $name => $abundance) {
                $this->info("    - {$name}: {$abundance:.4f}");
            }
        }

        $outputFile = "{$outputPath}/unmixing_" . time() . ".json";
        file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));
        $this->info("Results saved to: {$outputFile}");

        return Command::SUCCESS;
    }

    protected function runAll(array $imageData, string $outputPath): int
    {
        $this->info("Running all mineralogy analyses...");

        // Crosta PCA for hydroxyl
        $this->runCrostaPCA($imageData, 'hydroxyl', $outputPath);
        $this->newLine();

        // Crosta PCA for iron
        $this->runCrostaPCA($imageData, 'iron', $outputPath);
        $this->newLine();

        // Available minerals
        $minerals = GeoMin::getAvailableMinerals();
        $this->info("Available reference minerals: " . implode(', ', $minerals));

        return Command::SUCCESS;
    }

    protected function loadImageData(string $path): array
    {
        return json_decode(file_get_contents($path), true);
    }
}

<?php

namespace GeoMin\Console\Commands;

use GeoMin\Clients\DTO\SearchOptions;
use GeoMin\Facades\GeoMin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Fetch Satellite Data Command
 * 
 * Downloads satellite imagery from STAC endpoints for
 * specified geographic areas and time periods.
 * 
 * Usage:
 *   php artisan geomin:fetch --bbox="[minLon,minLat,maxLon,maxLat]" --date="2023-01-01/2023-12-31"
 *   php artisan geomin:fetch --collection="sentinel-2-l2a" --cloud-cover=10
 * 
 * @author Kazashim Kuzasuwat
 */
class FetchSatelliteData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'geomin:fetch
                           {--bbox= : Bounding box [minLon,minLat,maxLon,maxLat]}
                           {--geometry= : Geometry file path (GeoJSON)}
                           {--collection= : STAC collection ID}
                           {--date= : Date range (start/end or start/end)}
                           {--start-date= : Start date}
                           {--end-date= : End date}
                           {--cloud-cover= : Maximum cloud cover percentage}
                           {--limit=100 : Maximum number of results}
                           {--output= : Output directory for downloaded files}
                           {--dry-run : Show results without downloading}';

    /**
     * The console command description.
     */
    protected $description = 'Search and download satellite imagery from STAC endpoints';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Parse options
        $bbox = $this->parseBBox();
        $geometry = $this->option('geometry');
        $collection = $this->option('collection') ?: config('geomin.stac.default_collection', 'sentinel-2-l2a');
        $dateRange = $this->parseDateRange();
        $cloudCover = (float) ($this->option('cloud-cover') ?? 100);
        $limit = (int) ($this->option('limit') ?? 100);
        $outputPath = $this->option('output') ?: storage_path('geomin/downloads');
        $dryRun = $this->option('dry-run');

        // Build search options
        $options = new SearchOptions([
            'collections' => [$collection],
            'bbox' => $bbox,
            'geometry' => $geometry ? json_decode(file_get_contents($geometry), true) : null,
            'startDate' => $dateRange['start'],
            'endDate' => $dateRange['end'],
            'cloudCover' => $cloudCover,
            'limit' => $limit,
        ]);

        $this->info("Searching for satellite imagery...");
        $this->info("Collection: {$collection}");
        $this->info("Cloud Cover: < {$cloudCover}%");
        
        if ($bbox) {
            $this->info("Bounding Box: " . implode(', ', $bbox));
        }

        if ($dateRange['start']) {
            $this->info("Date Range: {$dateRange['start']->format('Y-m-d')} to {$dateRange['end']->format('Y-m-d')}");
        }

        $this->newLine();

        // Perform search
        try {
            $results = GeoMin::stac()->search($options);
            
            if (empty($results)) {
                $this->warn('No scenes found matching criteria.');
                return Command::FAILURE;
            }

            $this->info("Found " . count($results) . " scenes:");
            
            // Display results
            $headers = ['ID', 'Date', 'Cloud%', 'Resolution', 'Bands'];
            $rows = array_map(function ($result) {
                return [
                    substr($result->sceneId, 0, 30),
                    $result->acquisitionTime?->format('Y-m-d') ?? 'N/A',
                    $result->cloudCover,
                    $result->resolution . 'm',
                    count($result->bands),
                ];
            }, $results);

            $this->table($headers, $rows);

            if ($dryRun) {
                $this->info('Dry run - no files downloaded.');
                return Command::SUCCESS;
            }

            // Download selected scenes
            if ($this->confirm('Download all scenes?', true)) {
                return $this->downloadScenes($results, $outputPath);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Search failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Parse bounding box from option.
     */
    protected function parseBBox(): ?array
    {
        $bbox = $this->option('bbox');
        
        if (!$bbox) {
            return null;
        }

        $values = array_map('floatval', json_decode($bbox, true) ?: explode(',', $bbox));
        
        if (count($values) !== 4) {
            $this->error('Bounding box must have 4 values: [minLon,minLat,maxLon,maxLat]');
            return null;
        }

        return $values;
    }

    /**
     * Parse date range from options.
     */
    protected function parseDateRange(): array
    {
        $startDate = null;
        $endDate = null;

        // Check combined option
        if ($dateOption = $this->option('date')) {
            $parts = explode('/', $dateOption);
            if (count($parts) === 2) {
                $startDate = \DateTime::createFromFormat('Y-m-d', trim($parts[0]));
                $endDate = \DateTime::createFromFormat('Y-m-d', trim($parts[1]));
            }
        } else {
            // Check individual options
            if ($startOption = $this->option('start-date')) {
                $startDate = \DateTime::createFromFormat('Y-m-d', $startOption);
            }
            if ($endOption = $this->option('end-date')) {
                $endDate = \DateTime::createFromFormat('Y-m-d', $endOption);
            }
        }

        // Default to last 30 days if no date specified
        if (!$startDate && !$endDate) {
            $endDate = new \DateTime();
            $startDate = clone $endDate;
            $startDate->modify('-30 days');
        }

        return ['start' => $startDate, 'end' => $endDate];
    }

    /**
     * Download selected scenes.
     */
    protected function downloadScenes(array $results, string $outputPath): int
    {
        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $this->output->progressStart(count($results));
        $downloaded = 0;

        foreach ($results as $result) {
            try {
                // Get item from catalog
                $item = GeoMin::stac()->getItem($result->sceneId, $result->metadata['collection'] ?? null);
                
                if (!$item) {
                    $this->error("Could not retrieve item: {$result->sceneId}");
                    continue;
                }

                // Download key bands
                $bands = ['red', 'green', 'blue', 'nir', 'swir1', 'swir2'];
                $scenePath = "{$outputPath}/{$result->sceneId}";
                mkdir($scenePath, 0755, true);

                foreach ($bands as $band) {
                    $url = GeoMin::stac()->getAssetUrl($item, $band);
                    if ($url) {
                        $bandPath = "{$scenePath}/{$band}.tif";
                        if (GeoMin::stac()->downloadAsset($url, $bandPath)) {
                            $this->info("Downloaded {$band}.tif");
                        }
                    }
                }

                $downloaded++;

            } catch (\Exception $e) {
                $this->error("Failed to download {$result->sceneId}: {$e->getMessage()}");
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->info("Downloaded {$downloaded} of " . count($results) . " scenes.");

        return $downloaded === count($results) ? Command::SUCCESS : Command::PARTIAL;
    }
}

<?php

namespace GeoMin\Jobs;

use GeoMin\Events\AnalysisCompleted;
use GeoMin\Events\AnalysisFailed;
use GeoMin\Events\AnalysisStarted;
use GeoMin\Models\SpatialAnalysis;
use GeoMin\Services\GeoMinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process Satellite Image Job
 * 
 * Queueable job for processing satellite imagery in the background.
 * Supports anomaly detection, cloud masking, and mineral mapping.
 * 
 * @author Kazashim Kuzasuwat
 */
class ProcessSatelliteImage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The analysis model.
     */
    protected SpatialAnalysis $analysis;

    /**
     * Job type (anomaly_detection, cloud_masking, etc.)
     */
    protected string $jobType;

    /**
     * Processing options.
     */
    protected array $options;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(SpatialAnalysis $analysis, string $jobType, array $options = [])
    {
        $this->analysis = $analysis;
        $this->jobType = $jobType;
        $this->options = $options;
    }

    /**
     * Get the unique ID for this job.
     */
    public function uniqueId(): string
    {
        return "geomin_{$this->analysis->id}";
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'geomin',
            "geomin:{$this->jobType}",
            "geomin:{$this->analysis->id}",
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(GeoMinService $geomin): void
    {
        // Update status to processing
        $this->analysis->markAsProcessing();
        
        // Dispatch started event
        AnalysisStarted::dispatch($this->analysis);

        Log::info('Starting satellite image processing job', [
            'analysis_id' => $this->analysis->id,
            'job_type' => $this->jobType,
        ]);

        try {
            // Load input data
            $filePath = $this->analysis->getInputFile();
            if (!$filePath || !file_exists($filePath)) {
                throw new \RuntimeException("Input file not found: {$filePath}");
            }

            $data = $this->loadData($filePath);

            // Process based on job type
            $result = match ($this->jobType) {
                'anomaly_detection' => $this->processAnomalyDetection($geomin, $data),
                'cloud_masking' => $this->processCloudMasking($geomin, $data),
                'mineral_mapping' => $this->processMineralMapping($geomin, $data),
                'spectral_index' => $this->processSpectralIndex($geomin, $data),
                default => throw new \InvalidArgumentException("Unknown job type: {$this->jobType}"),
            };

            // Save result
            $this->analysis->saveResult($result);
            $this->analysis->markAsCompleted($result['statistics'] ?? []);

            // Dispatch completed event
            AnalysisCompleted::dispatch($this->analysis, $result);

            Log::info('Satellite image processing completed', [
                'analysis_id' => $this->analysis->id,
                'job_type' => $this->jobType,
            ]);

        } catch (Throwable $e) {
            Log::error('Satellite image processing failed', [
                'analysis_id' => $this->analysis->id,
                'job_type' => $this->jobType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark as failed
            $this->analysis->markAsFailed($e->getMessage());

            // Dispatch failed event
            AnalysisFailed::dispatch($this->analysis, $e->getMessage());

            // Retry if attempts remain
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * Process anomaly detection.
     */
    protected function processAnomalyDetection(GeoMinService $geomin, array $data): array
    {
        $algorithm = $this->options['algorithm'] ?? 'rx';
        $contamination = $this->options['contamination'] ?? 0.01;

        return $geomin->detectAnomalies($data, $algorithm, [
            'contamination' => $contamination,
        ]);
    }

    /**
     * Process cloud masking.
     */
    protected function processCloudMasking(GeoMinService $geomin, array $data): array
    {
        $algorithm = $this->options['algorithm'] ?? 'sentinel2';

        return $geomin->maskClouds($data, $algorithm);
    }

    /**
     * Process mineral mapping.
     */
    protected function processMineralMapping(GeoMinService $geomin, array $data): array
    {
        $method = $this->options['method'] ?? 'crosta';
        $target = $this->options['target'] ?? 'hydroxyl';

        return $geomin->crostaPCA($data, $target);
    }

    /**
     * Process spectral index.
     */
    protected function processSpectralIndex(GeoMinService $geomin, array $data): array
    {
        $index = $this->options['index'] ?? 'ndvi';

        return $geomin->calculateIndex($data, $index);
    }

    /**
     * Load data from file.
     */
    protected function loadData(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => json_decode(file_get_contents($filePath), true),
            'npy' => $this->loadNpy($filePath),
            default => throw new \InvalidArgumentException("Unsupported format: {$extension}"),
        };
    }

    /**
     * Load NumPy file.
     */
    protected function loadNpy(string $path): array
    {
        // Simplified NPY loader
        $handle = fopen($path, 'rb');
        fread($handle, 6); // Magic
        $version = unpack('C2', fread($handle, 2));
        $headerLen = $version[1] === 1 ? 2 : 4;
        $headerLength = unpack('V', fread($handle, $headerLen))[1];
        fread($handle, $headerLength); // Header
        $data = fread($handle, filesize($path) - ftell($handle));
        fclose($handle);

        return array_values(unpack('d*', $data));
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessSatelliteImage job permanently failed', [
            'analysis_id' => $this->analysis->id,
            'job_type' => $this->jobType,
            'error' => $exception?->getMessage(),
        ]);

        $this->analysis->markAsFailed($exception?->getMessage() ?? 'Unknown error');
        AnalysisFailed::dispatch($this->analysis, $exception?->getMessage() ?? 'Unknown error');
    }
}

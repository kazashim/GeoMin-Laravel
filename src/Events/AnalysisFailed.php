<?php

namespace GeoMin\Events;

use GeoMin\Models\SpatialAnalysis;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Analysis Failed Event
 * 
 * Dispatched when a GeoMin analysis fails.
 * 
 * @property SpatialAnalysis $analysis
 * @property string $error
 * 
 * @author Kazashim Kuzasuwat
 */
class AnalysisFailed
{
    use Dispatchable, SerializesModels;

    /**
     * The failed analysis.
     */
    public SpatialAnalysis $analysis;

    /**
     * The error message.
     */
    public string $error;

    /**
     * Create a new event instance.
     */
    public function __construct(SpatialAnalysis $analysis, string $error)
    {
        $this->analysis = $analysis;
        $this->error = $error;
    }

    /**
     * Get analysis type.
     */
    public function getAnalysisType(): string
    {
        return $this->analysis->type;
    }

    /**
     * Get analysis ID.
     */
    public function getAnalysisId(): int
    {
        return $this->analysis->id;
    }
}

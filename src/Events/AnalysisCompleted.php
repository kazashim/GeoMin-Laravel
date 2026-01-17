<?php

namespace GeoMin\Events;

use GeoMin\Models\SpatialAnalysis;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Analysis Completed Event
 * 
 * Dispatched when a GeoMin analysis completes successfully.
 * 
 * @property SpatialAnalysis $analysis
 * @property array $result
 * 
 * @author Kazashim Kuzasuwat
 */
class AnalysisCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * The completed analysis.
     */
    public SpatialAnalysis $analysis;

    /**
     * The analysis result.
     */
    public array $result;

    /**
     * Create a new event instance.
     */
    public function __construct(SpatialAnalysis $analysis, array $result)
    {
        $this->analysis = $analysis;
        $this->result = $result;
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

<?php

namespace GeoMin\Events;

use GeoMin\Models\SpatialAnalysis;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Analysis Started Event
 * 
 * Dispatched when a GeoMin analysis starts processing.
 * 
 * @property SpatialAnalysis $analysis
 * 
 * @author Kazashim Kuzasuwat
 */
class AnalysisStarted
{
    use Dispatchable, SerializesModels;

    /**
     * The analysis being processed.
     */
    public SpatialAnalysis $analysis;

    /**
     * Create a new event instance.
     */
    public function __construct(SpatialAnalysis $analysis)
    {
        $this->analysis = $analysis;
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

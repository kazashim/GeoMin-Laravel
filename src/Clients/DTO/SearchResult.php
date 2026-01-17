<?php

namespace GeoMin\Clients\DTO;

use DateTimeInterface;

/**
 * Search Result Data Transfer Object
 * 
 * Represents a single satellite scene/search result from STAC.
 * 
 * @author Kazashim Kuzasuwat
 */
class SearchResult
{
    /**
     * Create a new SearchResult instance.
     *
     * @param string $sceneId Unique scene identifier
     * @param string $provider Data provider (e.g., 'sentinel', 'landsat')
     * @param DateTimeInterface|null $acquisitionTime Image acquisition timestamp
     * @param float $cloudCover Cloud cover percentage (0-100)
     * @param array|null $geometry STAC geometry (GeoJSON)
     * @param array $bands Available bands in the scene
     * @param int $resolution Ground resolution in meters
     * @param array $metadata Additional metadata
     */
    public function __construct(
        public readonly string $sceneId,
        public readonly string $provider,
        public readonly ?DateTimeInterface $acquisitionTime,
        public readonly float $cloudCover,
        public readonly ?array $geometry,
        public readonly array $bands,
        public readonly int $resolution,
        public readonly array $metadata = []
    ) {}

    /**
     * Create from STAC feature array.
     */
    public static function fromFeature(array $feature): self
    {
        $properties = $feature['properties'] ?? [];

        return new self(
            sceneId: $feature['id'],
            provider: $feature['collection'] ?? 'unknown',
            acquisitionTime: self::parseDateTime($properties['datetime'] ?? null),
            cloudCover: $properties['eo:cloud_cover'] ?? 0,
            geometry: $feature['geometry'] ?? null,
            bands: array_keys($feature['assets'] ?? []),
            resolution: self::estimateResolution($feature),
            metadata: [
                'collection' => $feature['collection'] ?? null,
                'bbox' => $feature['bbox'] ?? null,
                'links' => $feature['links'] ?? [],
            ]
        );
    }

    /**
     * Parse datetime string.
     */
    protected static function parseDateTime(?string $dateTimeString): ?DateTimeInterface
    {
        if (!$dateTimeString) {
            return null;
        }

        try {
            return DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateTimeString)
                ?? DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateTimeString)
                ?? new DateTime($dateTimeString);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Estimate resolution from feature.
     */
    protected static function estimateResolution(array $feature): int
    {
        // Try GSD property
        $gsd = $feature['properties']['gsd'] ?? null;
        if ($gsd) {
            return (int) $gsd;
        }

        // Infer from collection
        $collection = $feature['collection'] ?? '';
        
        return match ($collection) {
            'sentinel-2-l2a', 'sentinel-2-l1c' => 10,
            'landsat-c2-l2', 'landsat-c2-l1', 'landsat-8-l1' => 30,
            default => 10,
        };
    }

    /**
     * Get bounding box.
     */
    public function getBoundingBox(): ?array
    {
        return $this->metadata['bbox'] ?? null;
    }

    /**
     * Check if scene has specific band.
     */
    public function hasBand(string $bandName): bool
    {
        return in_array(strtolower($bandName), array_map('strtolower', $this->bands));
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'scene_id' => $this->sceneId,
            'provider' => $this->provider,
            'acquisition_time' => $this->acquisitionTime?->format('Y-m-d'),
            'cloud_cover' => $this->cloudCover,
            'resolution' => $this->resolution,
            'bands' => $this->bands,
            'bbox' => $this->getBoundingBox(),
        ];
    }
}

<?php

namespace GeoMin\Clients\DTO;

use DateTimeInterface;

/**
 * Search Options Data Transfer Object
 * 
 * Defines parameters for searching satellite imagery via STAC.
 * 
 * @author Kazashim Kuzasuwat
 */
class SearchOptions
{
    /**
     * Create a new SearchOptions instance.
     *
     * @param array $config Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->collections = $config['collections'] ?? $config['collection'] ?? null;
        $this->bbox = $config['bbox'] ?? null;
        $this->geometry = $config['geometry'] ?? null;
        $this->startDate = $config['start_date'] ?? $config['startDate'] ?? null;
        $this->endDate = $config['end_date'] ?? $config['endDate'] ?? null;
        $this->cloudCover = $config['cloud_cover'] ?? $config['cloudCover'] ?? null;
        $this->limit = $config['limit'] ?? 100;
        $this->query = $config['query'] ?? [];
    }

    /**
     * Collections to search (STAC collection IDs)
     */
    public ?array $collections;

    /**
     * Bounding box [minLon, minLat, maxLon, maxLat]
     */
    public ?array $bbox;

    /**
     * Geometry (GeoJSON or geometry array)
     */
    public ?array $geometry;

    /**
     * Start date for temporal filter
     */
    public ?DateTimeInterface $startDate;

    /**
     * End date for temporal filter
     */
    public ?DateTimeInterface $endDate;

    /**
     * Maximum cloud cover percentage (0-100)
     */
    public ?float $cloudCover;

    /**
     * Maximum number of results
     */
    public int $limit;

    /**
     * Additional query filters
     */
    public array $query;

    /**
     * Set collections to search.
     */
    public function collections(array $collections): self
    {
        $this->collections = $collections;
        return $this;
    }

    /**
     * Set single collection.
     */
    public function collection(string $collection): self
    {
        $this->collections = [$collection];
        return $this;
    }

    /**
     * Set bounding box.
     */
    public function bbox(array $bbox): self
    {
        $this->bbox = $bbox;
        return $this;
    }

    /**
     * Set geometry filter.
     */
    public function geometry(array $geometry): self
    {
        $this->geometry = $geometry;
        return $this;
    }

    /**
     * Set date range.
     */
    public function date(DateTimeInterface $start, DateTimeInterface $end): self
    {
        $this->startDate = $start;
        $this->endDate = $end;
        return $this;
    }

    /**
     * Set start date.
     */
    public function startDate(DateTimeInterface $date): self
    {
        $this->startDate = $date;
        return $this;
    }

    /**
     * Set end date.
     */
    public function endDate(DateTimeInterface $date): self
    {
        $this->endDate = $date;
        return $this;
    }

    /**
     * Set cloud cover filter.
     */
    public function cloudCover(float $maxCloudCover): self
    {
        $this->cloudCover = $maxCloudCover;
        return $this;
    }

    /**
     * Set maximum results limit.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Add additional query filter.
     */
    public function where(string $field, string $operator, $value): self
    {
        $this->query[$field] = [$operator => $value];
        return $this;
    }

    /**
     * Create from array (static factory).
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Convert to array for STAC request.
     */
    public function toArray(): array
    {
        $array = [
            'limit' => $this->limit,
        ];

        if ($this->collections) {
            $array['collections'] = $this->collections;
        }

        if ($this->bbox) {
            $array['bbox'] = $this->bbox;
        }

        if ($this->geometry) {
            $array['geometry'] = $this->geometry;
        }

        if ($this->startDate || $this->endDate) {
            $start = $this->startDate?->format('Y-m-d') ?? '1900-01-01';
            $end = $this->endDate?->format('Y-m-d') ?? now()->format('Y-m-d');
            $array['datetime'] = "{$start}/{$end}";
        }

        if ($this->cloudCover !== null) {
            $this->query['eo:cloud_cover'] = ['lte' => $this->cloudCover];
        }

        if (!empty($this->query)) {
            $array['query'] = $this->query;
        }

        return $array;
    }
}

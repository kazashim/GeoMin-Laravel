<?php

namespace GeoMin\Clients;

use GeoMin\Clients\DTO\SearchResult;
use GeoMin\Clients\DTO\SearchOptions;
use GeoMin\Exceptions\STACException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * STAC Client for GeoMin
 * 
 * Provides access to SpatioTemporal Asset Catalog (STAC) endpoints
 * for querying and downloading satellite imagery from various providers
 * including AWS Earth Search, Microsoft Planetary Computer, and more.
 * 
 * @author Kazashim Kuzasuwat
 */
class STACClient
{
    /**
     * HTTP Client instance
     */
    protected Client $httpClient;

    /**
     * STAC endpoint URL
     */
    protected string $endpoint;

    /**
     * API key or token for authenticated endpoints
     */
    protected ?string $apiKey;

    /**
     * Available STAC endpoints
     */
    public const ENDPOINTS = [
        'aws' => 'https://earth-search.aws.element84.com/v1',
        'planetary_computer' => 'https://planetarycomputer.microsoft.com/api/stac/v1',
        'element84' => 'https://api.stac.terrascope.be/v1',
        'usgs' => 'https://landsatlook.usgs.gov/stac',
    ];

    /**
     * Supported collections with metadata
     */
    public const COLLECTIONS = [
        'sentinel-2-l2a' => [
            'name' => 'Sentinel-2 Level-2A',
            'provider' => 'Copernicus',
            'license' => 'proprietary',
            'resolution' => 10,
            'bands' => ['blue', 'green', 'red', 'nir08', 'swir16', 'swir22', 'scl'],
        ],
        'sentinel-2-l1c' => [
            'name' => 'Sentinel-2 Level-1C',
            'provider' => 'Copernicus',
            'license' => 'proprietary',
            'resolution' => 10,
            'bands' => ['blue', 'green', 'red', 'nir08', 'swir16', 'swir22'],
        ],
        'landsat-c2-l2' => [
            'name' => 'Landsat Collection 2 Level-2',
            'provider' => 'USGS',
            'license' => 'proprietary',
            'resolution' => 30,
            'bands' => ['blue', 'green', 'red', 'nir08', 'swir11', 'swir16', 'qa'],
        ],
        'landsat-c2-l1' => [
            'name' => 'Landsat Collection 2 Level-1',
            'provider' => 'USGS',
            'license' => 'proprietary',
            'resolution' => 30,
            'bands' => ['blue', 'green', 'red', 'nir08', 'swir11', 'swir16', 'pan'],
        ],
        'landsat-8-l1' => [
            'name' => 'Landsat 8 Level-1',
            'provider' => 'USGS',
            'license' => 'proprietary',
            'resolution' => 30,
            'bands' => ['blue', 'green', 'red', 'nir08', 'swir11', 'swir16', 'pan'],
        ],
    ];

    /**
     * Create a new STAC client instance.
     *
     * @param string $endpoint Endpoint name or full URL
     * @param string|null $apiKey API key for authenticated endpoints
     */
    public function __construct(string $endpoint = 'aws', ?string $apiKey = null)
    {
        $this->endpoint = $this->resolveEndpoint($endpoint);
        $this->apiKey = $apiKey;
        
        $this->httpClient = new Client([
            'base_uri' => $this->endpoint,
            'timeout' => config('geomin.stac.timeout', 60),
            'headers' => $this->getDefaultHeaders(),
        ]);
    }

    /**
     * Resolve endpoint URL from name or return as-is.
     */
    protected function resolveEndpoint(string $endpoint): string
    {
        if (isset(self::ENDPOINTS[$endpoint])) {
            return self::ENDPOINTS[$endpoint];
        }
        
        // Validate URL format
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("Invalid STAC endpoint: {$endpoint}");
        }
        
        return $endpoint;
    }

    /**
     * Get default HTTP headers for STAC requests.
     */
    protected function getDefaultHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'GeoMin-Laravel/1.0',
        ];

        if ($this->apiKey) {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        return $headers;
    }

    /**
     * Search for satellite imagery.
     *
     * @param SearchOptions|array $options Search parameters
     * @return SearchResult[]
     */
    public function search($options): array
    {
        $options = $this->normalizeSearchOptions($options);
        
        // Check cache first
        $cacheKey = $this->getCacheKey($options);
        if (config('geomin.stac.cache_enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::debug('STAC search result retrieved from cache');
                return $cached;
            }
        }

        try {
            $response = $this->httpClient->post('search', [
                'json' => $this->buildSearchBody($options),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $results = $this->parseSearchResults($data);

            // Cache results
            if (config('geomin.stac.cache_enabled', true)) {
                $ttl = config('geomin.stac.cache_ttl', 60);
                Cache::put($cacheKey, $results, now()->addMinutes($ttl));
            }

            return $results;

        } catch (GuzzleException $e) {
            Log::error('STAC search failed', ['error' => $e->getMessage()]);
            throw new STACException("STAC search failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Normalize search options to SearchOptions object.
     */
    protected function normalizeSearchOptions($options): SearchOptions
    {
        if ($options instanceof SearchOptions) {
            return $options;
        }

        return new SearchOptions($options);
    }

    /**
     * Build STAC search request body.
     */
    protected function buildSearchBody(SearchOptions $options): array
    {
        $body = [
            'collections' => $options->collections ?? [config('geomin.stac.default_collection', 'sentinel-2-l2a')],
            'limit' => $options->limit ?? config('geomin.stac.max_results', 100),
        ];

        // Add bounding box
        if ($options->bbox) {
            $body['bbox'] = $options->bbox;
        } elseif ($options->geometry) {
            $body['bbox'] = $this->geometryToBBox($options->geometry);
        }

        // Add datetime filter
        if ($options->startDate || $options->endDate) {
            $start = $options->startDate?->format('Y-m-d') ?? '1900-01-01';
            $end = $options->endDate?->format('Y-m-d') ?? now()->format('Y-m-d');
            $body['datetime'] = "{$start}/{$end}";
        }

        // Add query filters
        $query = [];
        
        if ($options->cloudCover !== null) {
            $query['eo:cloud_cover'] = ['lte' => $options->cloudCover];
        }

        if (!empty($query)) {
            $body['query'] = $query;
        }

        return $body;
    }

    /**
     * Parse STAC search response into SearchResult objects.
     */
    protected function parseSearchResults(array $data): array
    {
        $results = [];

        foreach ($data['features'] ?? [] as $feature) {
            $results[] = $this->featureToSearchResult($feature);
        }

        return $results;
    }

    /**
     * Convert STAC feature to SearchResult DTO.
     */
    protected function featureToSearchResult(array $feature): SearchResult
    {
        $properties = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? null;

        return new SearchResult(
            sceneId: $feature['id'],
            provider: $feature['collection'] ?? 'unknown',
            acquisitionTime: $this->parseDateTime($properties['datetime'] ?? $properties['acquired'] ?? null),
            cloudCover: $properties['eo:cloud_cover'] ?? 0,
            geometry: $geometry,
            bands: array_keys($feature['assets'] ?? []),
            resolution: $this->getResolution($feature),
            metadata: [
                'collection' => $feature['collection'],
                'bbox' => $feature['bbox'] ?? null,
                'links' => $feature['links'] ?? [],
                'assets' => array_keys($feature['assets'] ?? []),
            ]
        );
    }

    /**
     * Parse datetime string to Carbon instance.
     */
    protected function parseDateTime(?string $dateTimeString): ?\DateTimeInterface
    {
        if (!$dateTimeString) {
            return null;
        }

        try {
            return \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateTimeString)
                ?? \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateTimeString)
                ?? new \DateTime($dateTimeString);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get resolution from STAC feature.
     */
    protected function getResolution(array $feature): int
    {
        // Try to get from common properties
        $gsd = $feature['properties']['gsd'] ?? null;
        if ($gsd) {
            return (int) $gsd;
        }

        // Try to infer from collection
        $collection = $feature['collection'] ?? '';
        if (isset(self::COLLECTIONS[$collection])) {
            return self::COLLECTIONS[$collection]['resolution'];
        }

        return 10; // Default
    }

    /**
     * Convert geometry to bounding box.
     */
    protected function geometryToBBox(array $geometry): array
    {
        if (isset($geometry['bbox'])) {
            return $geometry['bbox'];
        }

        if (isset($geometry['type']) && $geometry['type'] === 'Polygon') {
            $coordinates = $geometry['coordinates'][0];
            $lons = array_column($coordinates, 0);
            $lats = array_column($coordinates, 1);
            return [min($lons), min($lats), max($lons), max($lats)];
        }

        return [-180, -90, 180, 90];
    }

    /**
     * Generate cache key for search results.
     */
    protected function getCacheKey(SearchOptions $options): string
    {
        $keyData = [
            $this->endpoint,
            $options->collections ?? config('geomin.stac.default_collection'),
            $options->bbox,
            $options->startDate?->format('Y-m-d'),
            $options->endDate?->format('Y-m-d'),
            $options->cloudCover,
        ];

        return 'geomin_stac_' . hash('sha256', json_encode($keyData));
    }

    /**
     * Get available collections from catalog.
     *
     * @return array Collection metadata
     */
    public function getCollections(): array
    {
        try {
            $response = $this->httpClient->get('collections');
            $data = json_decode($response->getBody()->getContents(), true);

            $collections = [];
            foreach ($data['collections'] ?? [] as $collection) {
                $collections[$collection['id']] = [
                    'id' => $collection['id'],
                    'title' => $collection['title'] ?? $collection['id'],
                    'description' => $collection['description'] ?? '',
                    'license' => $collection['license'] ?? 'unknown',
                ];
            }

            return $collections;

        } catch (GuzzleException $e) {
            Log::error('Failed to get STAC collections', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get information about a specific collection.
     *
     * @param string $collectionId Collection identifier
     * @return array|null Collection information
     */
    public function getCollectionInfo(string $collectionId): ?array
    {
        try {
            $response = $this->httpClient->get("collections/{$collectionId}");
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'id' => $data['id'],
                'title' => $data['title'] ?? $data['id'],
                'description' => $data['description'] ?? '',
                'license' => $data['license'] ?? 'unknown',
                'extent' => $data['extent'] ?? null,
            ];

        } catch (GuzzleException $e) {
            Log::error("Failed to get STAC collection: {$collectionId}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get item (scene) from catalog.
     *
     * @param string $itemId Item identifier
     * @param string|null $collectionId Collection identifier
     * @return array|null Item data
     */
    public function getItem(string $itemId, ?string $collectionId = null): ?array
    {
        try {
            $url = $collectionId 
                ? "collections/{$collectionId}/items/{$itemId}"
                : "items/{$itemId}";

            $response = $this->httpClient->get($url);
            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            Log::error("Failed to get STAC item: {$itemId}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get asset download URL from an item.
     *
     * @param array $item STAC item data
     * @param string $assetKey Asset key (e.g., 'red', 'nir08')
     * @return string|null Download URL
     */
    public function getAssetUrl(array $item, string $assetKey): ?string
    {
        $assets = $item['assets'] ?? [];
        
        // Try exact key match
        if (isset($assets[$assetKey]['href'])) {
            return $assets[$assetKey]['href'];
        }

        // Try case-insensitive match
        foreach ($assets as $key => $asset) {
            if (strtolower($key) === strtolower($assetKey)) {
                return $asset['href'] ?? null;
            }
        }

        return null;
    }

    /**
     * Download asset to local storage.
     *
     * @param string $url Asset URL
     * @param string $destinationPath Local destination path
     * @return bool Success status
     */
    public function downloadAsset(string $url, string $destinationPath): bool
    {
        try {
            $response = $this->httpClient->get($url, [
                'sink' => $destinationPath,
            ]);

            return $response->getStatusCode() === 200;

        } catch (GuzzleException $e) {
            Log::error('Failed to download asset', ['url' => $url, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Filter results by cloud cover.
     *
     * @param SearchResult[] $results Search results
     * @param float $maxCloudCover Maximum cloud cover percentage
     * @return SearchResult[]
     */
    public function filterByCloudCover(array $results, float $maxCloudCover): array
    {
        return array_filter($results, function (SearchResult $result) use ($maxCloudCover) {
            return $result->cloudCover <= $maxCloudCover;
        });
    }

    /**
     * Filter results by date range.
     *
     * @param SearchResult[] $results Search results
     * @param \DateTimeInterface|null $startDate Start date
     * @param \DateTimeInterface|null $endDate End date
     * @return SearchResult[]
     */
    public function filterByDate(array $results, ?\DateTimeInterface $startDate, ?\DateTimeInterface $endDate): array
    {
        return array_filter($results, function (SearchResult $result) use ($startDate, $endDate) {
            if (!$result->acquisitionTime) {
                return true;
            }

            if ($startDate && $result->acquisitionTime < $startDate) {
                return false;
            }

            if ($endDate && $result->acquisitionTime > $endDate) {
                return false;
            }

            return true;
        });
    }

    /**
     * Sort results by acquisition date.
     *
     * @param SearchResult[] $results Search results
     * @param bool $ascending Sort ascending (oldest first)
     * @return SearchResult[]
     */
    public function sortByDate(array $results, bool $ascending = true): array
    {
        usort($results, function (SearchResult $a, SearchResult $b) use ($ascending) {
            if (!$a->acquisitionTime && !$b->acquisitionTime) {
                return 0;
            }
            
            if (!$a->acquisitionTime) {
                return $ascending ? 1 : -1;
            }
            
            if (!$b->acquisitionTime) {
                return $ascending ? -1 : 1;
            }

            $comparison = $a->acquisitionTime <=> $b->acquisitionTime;
            return $ascending ? $comparison : -$comparison;
        });

        return $results;
    }

    /**
     * Get the endpoint URL.
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Check if client is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->endpoint);
    }
}

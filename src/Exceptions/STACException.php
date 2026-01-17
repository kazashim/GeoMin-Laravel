<?php

namespace GeoMin\Exceptions;

use GeoMin\Exceptions\GeoMinException;

/**
 * STAC Exception
 * 
 * Exception thrown when STAC client operations fail.
 * 
 * @author Kazashim Kuzasuwat
 */
class STACException extends GeoMinException
{
    /**
     * Common error codes.
     */
    public const CONNECTION_FAILED = 100;
    public const SEARCH_FAILED = 101;
    public const ITEM_NOT_FOUND = 102;
    public const DOWNLOAD_FAILED = 103;
    public const INVALID_ENDPOINT = 104;
    public const AUTHENTICATION_FAILED = 105;

    /**
     * Create a new STAC exception.
     */
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for connection failure.
     */
    public static function connectionFailed(string $endpoint, string $error): self
    {
        return new self(
            "Failed to connect to STAC endpoint '{$endpoint}': {$error}",
            self::CONNECTION_FAILED
        )->setContext(['endpoint' => $endpoint, 'error' => $error]);
    }

    /**
     * Create exception for search failure.
     */
    public static function searchFailed(string $error): self
    {
        return new self(
            "STAC search failed: {$error}",
            self::SEARCH_FAILED
        )->setContext(['error' => $error]);
    }

    /**
     * Create exception for item not found.
     */
    public static function itemNotFound(string $itemId, ?string $collectionId = null): self
    {
        return new self(
            "STAC item not found: {$itemId}",
            self::ITEM_NOT_FOUND
        )->setContext(['item_id' => $itemId, 'collection_id' => $collectionId]);
    }
}

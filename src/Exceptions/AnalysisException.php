<?php

namespace GeoMin\Exceptions;

use GeoMin\Exceptions\GeoMinException;

/**
 * Analysis Exception
 * 
 * Exception thrown when analysis operations fail.
 * 
 * @author Kazashim Kuzasuwat
 */
class AnalysisException extends GeoMinException
{
    /**
     * Common error codes.
     */
    public const INVALID_DATA = 200;
    public const ALGORITHM_ERROR = 201;
    public const MEMORY_LIMIT = 202;
    public const TIMEOUT = 203;
    public const INVALID_PARAMETER = 204;

    /**
     * Create a new analysis exception.
     */
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for invalid data.
     */
    public static function invalidData(string $reason): self
    {
        return new self(
            "Invalid data for analysis: {$reason}",
            self::INVALID_DATA
        )->setContext(['reason' => $reason]);
    }

    /**
     * Create exception for algorithm error.
     */
    public static function algorithmError(string $algorithm, string $error): self
    {
        return new self(
            "Algorithm '{$algorithm}' failed: {$error}",
            self::ALGORITHM_ERROR
        )->setContext(['algorithm' => $algorithm, 'error' => $error]);
    }

    /**
     * Create exception for memory limit exceeded.
     */
    public static function memoryLimitExceeded(int $used, int $limit): self
    {
        return new self(
            "Memory limit exceeded. Used: {$used} bytes, Limit: {$limit} bytes",
            self::MEMORY_LIMIT
        )->setContext(['used_memory' => $used, 'memory_limit' => $limit]);
    }
}

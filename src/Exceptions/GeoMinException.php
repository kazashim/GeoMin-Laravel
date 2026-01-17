<?php

namespace GeoMin\Exceptions;

use Exception;

/**
 * GeoMin Exception
 * 
 * Base exception class for all GeoMin-related errors.
 * 
 * @author Kazashim Kuzasuwat
 */
class GeoMinException extends Exception
{
    /**
     * Additional context for the exception.
     */
    protected array $context = [];

    /**
     * Set additional context.
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Get context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get full message with context.
     */
    public function getFullMessage(): string
    {
        $message = $this->getMessage();
        
        if (!empty($this->context)) {
            $message .= ' Context: ' . json_encode($this->context);
        }
        
        return $message;
    }
}
